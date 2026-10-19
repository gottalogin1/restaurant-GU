#!/usr/bin/env sh
# Shows each installed plugin's version and available update, then installs the updates
# (`make plugins`; `make update` runs it last). Runs inside the WP-CLI container.
#
# Skipped, because they update through this repository instead:
#   guam-restaurants-core  the site's own plugin (git pull)
#   woocommerce            pinned by WOOCOMMERCE_VERSION in the Makefile (git pull, then make update)
set -eu

# The container user has no writable home folder for the download cache.
export WP_CLI_CACHE_DIR="${WP_CLI_CACHE_DIR:-/tmp/wp-cli-cache}"

repo_managed="guam-restaurants-core woocommerce"

if [ "$(wp eval 'echo is_wp_error( wp_remote_head( "https://api.wordpress.org/plugins/update-check/1.1/", array( "timeout" => 15 ) ) ) ? "no" : "yes";')" != "yes" ]; then
	echo "Plugin updates skipped: api.wordpress.org cannot be reached from the WordPress container."
	exit 0
fi

# This listing asks wordpress.org (and premium plugins' own servers) for updates; later ones reuse it.
echo "Installed plugins (update_version is empty when the plugin is up to date):"
wp plugin list --fields=name,status,version,update,update_version

updates=""
for plugin in $(wp plugin list --update=available --field=name --skip-update-check); do
	case " $repo_managed " in
		*" $plugin "*) ;;
		*) updates="$updates $plugin" ;;
	esac
done

woo_latest=$(wp plugin list --name=woocommerce --update=available --field=update_version --skip-update-check)
if [ -n "$woo_latest" ]; then
	echo "WooCommerce $woo_latest is available. The version used here is pinned in the Makefile"
	echo "(WOOCOMMERCE_VERSION), so it changes through the repository, not here."
fi

if [ -z "$updates" ]; then
	echo "All other plugins are up to date."
	exit 0
fi

echo "Updating:$updates"
# shellcheck disable=SC2086 # One argument per plugin name.
wp plugin update $updates
