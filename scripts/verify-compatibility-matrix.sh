#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_ID="${EIT_MATRIX_RUN_ID:-$(date -u +%Y%m%d%H%M%S)-$$}"
DEFAULT_COMBOS=$'6.7|8.1|3.28.4\n6.8|8.2|4.0.0\n6.9|8.4|4.0.8'
COMBOS="${EIT_MATRIX_COMBOS:-$DEFAULT_COMBOS}"
MYSQL_IMAGE="${EIT_MATRIX_MYSQL_IMAGE:-mysql:8.4}"
CURRENT_CONTAINERS=()
CURRENT_VOLUMES=()
CURRENT_NETWORKS=()

cleanup() {
	if ( ( ${#CURRENT_CONTAINERS[@]} ) ); then
		docker rm -f "${CURRENT_CONTAINERS[@]}" >/dev/null 2>&1 || true
	fi
	if ( ( ${#CURRENT_VOLUMES[@]} ) ); then
		docker volume rm -f "${CURRENT_VOLUMES[@]}" >/dev/null 2>&1 || true
	fi
	if ( ( ${#CURRENT_NETWORKS[@]} ) ); then
		docker network rm "${CURRENT_NETWORKS[@]}" >/dev/null 2>&1 || true
	fi
	CURRENT_CONTAINERS=()
	CURRENT_VOLUMES=()
	CURRENT_NETWORKS=()
}

trap cleanup EXIT INT TERM

wait_for_database() {
	local container="$1"
	local attempt
	for attempt in $(seq 1 60); do
		if [[ "$(docker inspect --format '{{.State.Health.Status}}' "$container" 2>/dev/null || true)" == "healthy" ]]; then
			return 0
		fi
		sleep 1
	done
	return 1
}

wait_for_wordpress_files() {
	local container="$1"
	local attempt
	for attempt in $(seq 1 60); do
		if docker exec "$container" test -f /var/www/html/wp-includes/version.php; then
			return 0
		fi
		sleep 1
	done
	return 1
}

run_combo() {
	local wp_version="$1"
	local php_version="$2"
	local elementor_version="$3"
	local suffix="${RUN_ID//[^a-zA-Z0-9]/}-${wp_version//./}-${php_version//./}"
	local network="eit-matrix-net-${suffix}"
	local volume="eit-matrix-wp-${suffix}"
	local database="eit-matrix-db-${suffix}"
	local wordpress="eit-matrix-web-${suffix}"
	local wordpress_image="wordpress:${wp_version}-php${php_version}-apache"
	local cli_image="wordpress:cli-php${php_version}"
	local plugin_path="/var/www/html/wp-content/plugins/elementor-implementation-toolkit"
	local scripts=(
		verify-cpt.php
		verify-cct.php
		verify-blueprint-infrastructure.php
		verify-blueprint-kernel.php
		verify-blueprint-publication.php
		verify-reconciliation-integrity.php
		verify-legacy-first-activation-rollback.php
		verify-parameterized-routes.php
		verify-destructive-field-migrations.php
		verify-entry-storage-atomicity.php
		verify-collections.php
		verify-elementor-bridge.php
		verify-collection-performance.php
		verify-destructive-uninstall.php
	)

	cleanup
	docker image inspect "$wordpress_image" >/dev/null 2>&1 || docker pull "$wordpress_image"
	docker image inspect "$cli_image" >/dev/null 2>&1 || docker pull "$cli_image"
	docker image inspect "$MYSQL_IMAGE" >/dev/null 2>&1 || docker pull "$MYSQL_IMAGE"
	docker network create "$network" >/dev/null
	docker volume create "$volume" >/dev/null
	CURRENT_NETWORKS=( "$network" )
	CURRENT_VOLUMES=( "$volume" )

	docker run -d --name "$database" --network "$network" \
		-e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress -e MYSQL_USER=wordpress -e MYSQL_PASSWORD=wordpress \
		--health-cmd='mysqladmin ping -h 127.0.0.1 -proot --silent' --health-interval=1s --health-timeout=3s --health-retries=60 \
		"$MYSQL_IMAGE" >/dev/null
	CURRENT_CONTAINERS=( "$database" )
	wait_for_database "$database"

	docker run -d --name "$wordpress" --network "$network" \
		-e WORDPRESS_DB_HOST="${database}:3306" -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress \
		-v "${volume}:/var/www/html" -v "${ROOT_DIR}:${plugin_path}" \
		"$wordpress_image" >/dev/null
	CURRENT_CONTAINERS+=( "$wordpress" )
	wait_for_wordpress_files "$wordpress"

	wp_cli() {
		docker run --rm --network "$network" --volumes-from "$wordpress" \
			-e WORDPRESS_DB_HOST="${database}:3306" -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress \
			"$cli_image" "$@"
	}

	echo "Representative canary WordPress ${wp_version}, PHP ${php_version}, Elementor Free ${elementor_version}"
	wp_cli core install --url="http://eit-${suffix}.test" --title='EIT compatibility canary' --admin_user=eit --admin_password=eit-canary-only --admin_email=canary@example.test --skip-email
	wp_cli plugin install elementor --version="$elementor_version" --activate
	wp_cli plugin activate elementor-implementation-toolkit
	wp_cli eval "if ( ! str_starts_with( get_bloginfo( 'version' ), '${wp_version}' ) || PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '${php_version}' || ! defined( 'ELEMENTOR_VERSION' ) || ELEMENTOR_VERSION !== '${elementor_version}' ) { throw new RuntimeException( 'Compatibility runtime version mismatch.' ); }"
	wp_cli eval "WP_CLI::log( sprintf( 'Observed WordPress %s, PHP %s, Elementor Free %s.', get_bloginfo( 'version' ), PHP_VERSION, ELEMENTOR_VERSION ) );"

	docker run --rm --network "$network" --volumes-from "$wordpress" --entrypoint php "$cli_image" \
		"${plugin_path}/vendor/bin/phpunit" --configuration "${plugin_path}/phpunit.xml.dist"
	for script in "${scripts[@]}"; do
		wp_cli eval-file "wp-content/plugins/elementor-implementation-toolkit/scripts/${script}"
	done

	echo "PASS representative server canary: WordPress ${wp_version}, PHP ${php_version}, Elementor Free ${elementor_version}"
	cleanup
}

while IFS='|' read -r wp_version php_version elementor_version; do
	[[ -z "$wp_version" ]] && continue
	run_combo "$wp_version" "$php_version" "$elementor_version"
done <<< "$COMBOS"

echo "Representative WordPress/PHP/Elementor Free server canaries passed; browser, WooCommerce, Elementor Pro and human gates remain separate."
