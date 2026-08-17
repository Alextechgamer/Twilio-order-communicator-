<?php
/**
 * SQLite bootstrap + helpers for TOC License Server.
 */

class TOC_License_DB {

	/** @var PDO */
	private $pdo;

	public function __construct( $db_path ) {
		$dir = dirname( $db_path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		$this->pdo = new PDO( 'sqlite:' . $db_path );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$this->migrate();
	}

	public function pdo() {
		return $this->pdo;
	}

	private function migrate() {
		$this->pdo->exec(
			'CREATE TABLE IF NOT EXISTS licenses (
				license_key TEXT PRIMARY KEY,
				status TEXT NOT NULL DEFAULT "active",
				expires_at TEXT NULL,
				max_sites INTEGER NOT NULL DEFAULT 1,
				customer_email TEXT NULL,
				notes TEXT NULL,
				created_at TEXT NOT NULL
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE IF NOT EXISTS activations (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				license_key TEXT NOT NULL,
				site_url TEXT NOT NULL,
				instance_id TEXT NOT NULL,
				activated_at TEXT NOT NULL,
				last_seen_at TEXT NOT NULL,
				plugin_version TEXT NULL,
				UNIQUE(license_key, site_url, instance_id)
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE IF NOT EXISTS releases (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				slug TEXT NOT NULL,
				version TEXT NOT NULL,
				required_php TEXT NULL,
				required_wp TEXT NULL,
				package_path TEXT NOT NULL,
				changelog TEXT NULL,
				released_at TEXT NOT NULL,
				UNIQUE(slug, version)
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE IF NOT EXISTS rate_limits (
				bucket TEXT PRIMARY KEY,
				count INTEGER NOT NULL DEFAULT 0,
				window_start INTEGER NOT NULL
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE IF NOT EXISTS purchases (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				provider TEXT NOT NULL,
				provider_ref TEXT NOT NULL,
				customer_email TEXT NULL,
				item_slug TEXT NULL,
				tier TEXT NULL,
				amount_total INTEGER NOT NULL DEFAULT 0,
				currency TEXT NULL,
				license_key TEXT NULL,
				status TEXT NOT NULL DEFAULT "processing",
				created_at TEXT NOT NULL,
				UNIQUE(provider, provider_ref)
			)'
		);
		// Multi-product: bind keys to a product slug. NULL/empty = legacy key, valid
		// for the server default product only (see Api::update_check enforcement).
		$cols     = $this->pdo->query( 'PRAGMA table_info(licenses)' )->fetchAll();
		$has_slug = false;
		foreach ( $cols as $col ) {
			if ( isset( $col['name'] ) && 'item_slug' === $col['name'] ) {
				$has_slug = true;
				break;
			}
		}
		if ( ! $has_slug ) {
			$this->pdo->exec( 'ALTER TABLE licenses ADD COLUMN item_slug TEXT NULL' );
		}
	}

	/**
	 * Fixed-window rate limiter. Returns true if the call is allowed, false once the
	 * bucket has reached $max hits inside the current $window seconds. Portable across
	 * SQLite versions (no UPSERT); a rare first-insert race is caught and treated as
	 * allowed, which is acceptable for abuse throttling.
	 *
	 * @param string $bucket Identifier (e.g. "activate|1.2.3.4").
	 * @param int    $max    Max hits per window.
	 * @param int    $window Window length in seconds.
	 * @return bool
	 */
	public function rate_hit( $bucket, $max, $window ) {
		$now  = time();
		$stmt = $this->pdo->prepare( 'SELECT count, window_start FROM rate_limits WHERE bucket = ?' );
		$stmt->execute( array( $bucket ) );
		$row = $stmt->fetch();

		if ( ! $row ) {
			try {
				$ins = $this->pdo->prepare( 'INSERT INTO rate_limits (bucket, count, window_start) VALUES (?, 1, ?)' );
				$ins->execute( array( $bucket, $now ) );
			} catch ( PDOException $e ) {
				// Concurrent insert created the row first — count it as allowed.
				return true;
			}
			return true;
		}

		if ( ( $now - (int) $row['window_start'] ) >= (int) $window ) {
			$upd = $this->pdo->prepare( 'UPDATE rate_limits SET count = 1, window_start = ? WHERE bucket = ?' );
			$upd->execute( array( $now, $bucket ) );
			return true;
		}

		if ( (int) $row['count'] >= (int) $max ) {
			return false;
		}

		$upd = $this->pdo->prepare( 'UPDATE rate_limits SET count = count + 1 WHERE bucket = ?' );
		$upd->execute( array( $bucket ) );
		return true;
	}

	public function get_license( $key ) {
		$stmt = $this->pdo->prepare( 'SELECT * FROM licenses WHERE license_key = ?' );
		$stmt->execute( array( $key ) );
		$row = $stmt->fetch();
		return $row ?: null;
	}

	/**
	 * Insert a license row. Caller supplies a unique key (see generate_key loop).
	 *
	 * @param string      $key        License key.
	 * @param string|null $item_slug  Product slug the key is bound to ('' / null = server default).
	 * @param int         $max_sites  Activation limit.
	 * @param string|null $expires_at ISO expiry or null for lifetime.
	 * @param string|null $email      Customer email.
	 * @param string|null $notes      Free-form notes (e.g. "stripe:cs_...").
	 * @return void
	 */
	public function create_license( $key, $item_slug, $max_sites, $expires_at, $email, $notes ) {
		$stmt = $this->pdo->prepare(
			'INSERT INTO licenses (license_key, status, item_slug, expires_at, max_sites, customer_email, notes, created_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$stmt->execute(
			array(
				$key,
				'active',
				( '' === (string) $item_slug ) ? null : (string) $item_slug,
				$expires_at,
				max( 1, (int) $max_sites ),
				$email,
				$notes,
				gmdate( 'c' ),
			)
		);
	}

	/**
	 * Record a purchase. The UNIQUE(provider, provider_ref) constraint is the
	 * idempotency lock: returns false when this purchase was already recorded.
	 *
	 * @return bool True if inserted, false on duplicate.
	 */
	public function insert_purchase( $provider, $provider_ref, $email, $item_slug, $tier, $amount_total, $currency, $status ) {
		try {
			$stmt = $this->pdo->prepare(
				'INSERT INTO purchases (provider, provider_ref, customer_email, item_slug, tier, amount_total, currency, license_key, status, created_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)'
			);
			$stmt->execute( array( $provider, $provider_ref, $email, $item_slug, $tier, (int) $amount_total, $currency, $status, gmdate( 'c' ) ) );
		} catch ( PDOException $e ) {
			if ( '23000' === (string) $e->getCode() ) {
				return false;
			}
			throw $e;
		}
		return true;
	}

	public function get_purchase_by_ref( $provider, $provider_ref ) {
		$stmt = $this->pdo->prepare( 'SELECT * FROM purchases WHERE provider = ? AND provider_ref = ?' );
		$stmt->execute( array( $provider, $provider_ref ) );
		$row = $stmt->fetch();
		return $row ?: null;
	}

	public function finish_purchase( $provider, $provider_ref, $license_key, $status ) {
		$stmt = $this->pdo->prepare( 'UPDATE purchases SET license_key = ?, status = ? WHERE provider = ? AND provider_ref = ?' );
		$stmt->execute( array( $license_key, $status, $provider, $provider_ref ) );
	}

	public function count_activations( $key ) {
		$stmt = $this->pdo->prepare( 'SELECT COUNT(*) FROM activations WHERE license_key = ?' );
		$stmt->execute( array( $key ) );
		return (int) $stmt->fetchColumn();
	}

	public function find_activation( $key, $site_url, $instance_id ) {
		$stmt = $this->pdo->prepare(
			'SELECT * FROM activations WHERE license_key = ? AND site_url = ? AND instance_id = ?'
		);
		$stmt->execute( array( $key, $site_url, $instance_id ) );
		$row = $stmt->fetch();
		return $row ?: null;
	}

	public function upsert_activation( $key, $site_url, $instance_id, $plugin_version ) {
		$now  = gmdate( 'c' );
		$existing = $this->find_activation( $key, $site_url, $instance_id );
		if ( $existing ) {
			$stmt = $this->pdo->prepare(
				'UPDATE activations SET last_seen_at = ?, plugin_version = ? WHERE id = ?'
			);
			$stmt->execute( array( $now, $plugin_version, $existing['id'] ) );
			return $this->find_activation( $key, $site_url, $instance_id );
		}

		$stmt = $this->pdo->prepare(
			'INSERT INTO activations (license_key, site_url, instance_id, activated_at, last_seen_at, plugin_version)
			 VALUES (?, ?, ?, ?, ?, ?)'
		);
		$stmt->execute( array( $key, $site_url, $instance_id, $now, $now, $plugin_version ) );
		return $this->find_activation( $key, $site_url, $instance_id );
	}

	public function delete_activation( $key, $site_url, $instance_id ) {
		$stmt = $this->pdo->prepare(
			'DELETE FROM activations WHERE license_key = ? AND site_url = ? AND instance_id = ?'
		);
		$stmt->execute( array( $key, $site_url, $instance_id ) );
		return $stmt->rowCount() > 0;
	}

	public function touch_activation( $key, $site_url, $instance_id, $plugin_version = '' ) {
		$act = $this->find_activation( $key, $site_url, $instance_id );
		if ( ! $act ) {
			return null;
		}
		$stmt = $this->pdo->prepare(
			'UPDATE activations SET last_seen_at = ?, plugin_version = COALESCE(NULLIF(?, ""), plugin_version) WHERE id = ?'
		);
		$stmt->execute( array( gmdate( 'c' ), $plugin_version, $act['id'] ) );
		return $this->find_activation( $key, $site_url, $instance_id );
	}

	public function latest_release( $slug ) {
		$stmt = $this->pdo->prepare(
			'SELECT * FROM releases WHERE slug = ? ORDER BY released_at DESC, id DESC LIMIT 1'
		);
		$stmt->execute( array( $slug ) );
		$row = $stmt->fetch();
		return $row ?: null;
	}

	public function get_release( $slug, $version ) {
		$stmt = $this->pdo->prepare( 'SELECT * FROM releases WHERE slug = ? AND version = ?' );
		$stmt->execute( array( $slug, $version ) );
		$row = $stmt->fetch();
		return $row ?: null;
	}
}
