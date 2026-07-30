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
	}

	public function get_license( $key ) {
		$stmt = $this->pdo->prepare( 'SELECT * FROM licenses WHERE license_key = ?' );
		$stmt->execute( array( $key ) );
		$row = $stmt->fetch();
		return $row ?: null;
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
