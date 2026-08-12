# StoreCanvas — keeping customer artwork private

Customer-uploaded artwork and generated print composites are confidential (logos,
personal designs). StoreCanvas hardens their handling in three layers:

1. **REST enumeration is closed.** Uploaded/generated artwork is tagged with the
   `_sc_uploaded` / `_sc_generated` post-meta markers and filtered out of the public
   `/wp-json/wp/v2/media` listing for anonymous users (`SC_Print_Ready::filter_rest_media_query`).
   A one-time backfill (`SC_Print_Ready::backfill_artwork_markers`, run once when an
   administrator next loads wp-admin) marks artwork created before the markers existed.

2. **Links go through a signed proxy.** Everywhere StoreCanvas surfaces an artwork or
   composite link (admin order screen, print sheet, the generate-composite AJAX response)
   it uses `SC_Print_Ready::proxy_url()` — a capability/token-checked `admin-post.php`
   endpoint (`action=sc_dl`) that streams the file only to staff who manage orders/media
   or to the bearer of a signed, time-limited link. It never emits the raw
   `wp-content/uploads/...` URL. The proof email attaches the files directly (by path),
   so it never carried a public URL.

3. **Deny direct access to the files themselves (host step).** Layers 1–2 stop the plugin
   from *exposing* the raw URL, but a file left at its default `wp-content/uploads/...`
   path is still fetchable by anyone who already knows or guesses the exact path. Because
   the proxy serves files by absolute path (`get_attached_file()`), you can move
   StoreCanvas artwork into a protected location — or simply deny web access to it — with
   **no plugin code change**. Apply whichever matches your web server.

## Apache

Drop an `.htaccess` into the directory that holds the artwork (e.g. the year/month
uploads folder used for orders, or a dedicated subfolder you route SC uploads to):

```apache
# wp-content/uploads/.../.htaccess — deny direct web access; the plugin proxy still serves files
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
```

## nginx

nginx ignores `.htaccess`; add a `location` block to the server config and reload:

```nginx
# Deny direct access to StoreCanvas artwork; the WordPress proxy endpoint still serves them.
location ~* ^/wp-content/uploads/.*/sc-artwork/ {
    deny all;
    return 403;
}
```

Adjust the path to wherever your SC artwork lives. Verify afterward that:

- a direct hit on an artwork file returns **403**, and
- the admin order screen's **Print files** links and the customer proof email still work
  (they flow through the proxy / attachments, not the denied path).

## Note

This is defense in depth. The signed proxy, the REST filter, and the backfill are active
out of the box; the server-level deny is the operator step that closes the
"direct-URL-if-the-path-is-known" residual, and it needs a live host to verify against
your print-queue / proof-email / admin-preview flows.
