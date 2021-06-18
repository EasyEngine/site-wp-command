<?php

namespace EE\Site\Type;

use function EE\Utils\mustache_render;
use function EE\Site\Utils\get_ssl_policy;
use function EE\Site\Utils\sysctl_parameters;

class Site_WP_Docker {

	/**
	 * Generate docker-compose.yml according to requirement.
	 *
	 * @param array $filters Array of flags to determine the docker-compose.yml generation.
	 *                       Empty/Default -> Generates default WordPress docker-compose.yml
	 *                       ['le']        -> Enables letsencrypt in the generation.
	 * @param array $volumes Array containing volume info passable to \EE_DOCKER::get_mounting_volume_array().
	 *
	 * @return String docker-compose.yml content string.
	 */
	public function generate_docker_compose_yml( array $filters = [], $volumes ) {
		$img_versions = \EE\Utils\get_image_versions();
		$base         = [];

		$restart_default = [ 'name' => 'always' ];
		$network_default = [
			'net' => [
				[ 'name' => 'site-network' ],
			],
		];

		$network = [
			'networks_labels' => [
				'label' => [
					[ 'name' => 'org.label-schema.vendor=EasyEngine' ],
					[ 'name' => 'io.easyengine.site=${VIRTUAL_HOST}' ],
				],
			],
		];

		if ( in_array( 'db', $filters, true ) ) {
			// db configuration.
			$db['service_name'] = [ 'name' => 'db' ];
			$db['image']        = [ 'name' => 'easyengine/mariadb:' . $img_versions['easyengine/mariadb'] ];
			$db['restart']      = $restart_default;
			$db['labels']       = [
				'label' => [
					'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
				],
			];
			$db['volumes']      = [
				[
					'vol' => \EE_DOCKER::get_mounting_volume_array( $volumes['db'] ),
				],
			];
			$db['environment']  = [
				'env' => [
					[ 'name' => 'MYSQL_ROOT_PASSWORD' ],
					[ 'name' => 'MYSQL_DATABASE' ],
					[ 'name' => 'MYSQL_USER' ],
					[ 'name' => 'MYSQL_PASSWORD' ],
				],
			];
			$db['sysctls']      = sysctl_parameters();
			$db['networks']     = $network_default;
		}
		// PHP configuration.
		$php_image_key = ( 'latest' === $filters['php_version'] ? 'easyengine/php' : 'easyengine/php' . $filters['php_version'] );

		$php['service_name'] = [ 'name' => 'php' ];
		$php['image']        = [ 'name' => $php_image_key . ':' . $img_versions[ $php_image_key ] ];

		if ( in_array( 'db', $filters, true ) ) {
			$php['depends_on']['dependency'][] = [ 'name' => 'db' ];
		}

		if ( in_array( 'redis', $filters, true ) ) {
			$php['depends_on']['dependency'][] = [ 'name' => 'redis' ];
		}

		$php['restart']     = $restart_default;
		$php['labels']      = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$php['volumes']     = [
			[
				'vol' => \EE_DOCKER::get_mounting_volume_array( $volumes['php'] ),
			],
		];
		$php['environment'] = [
			'env' => [
				[ 'name' => 'WORDPRESS_DB_HOST' ],
				[ 'name' => 'WORDPRESS_DB_NAME' ],
				[ 'name' => 'WORDPRESS_DB_USER' ],
				[ 'name' => 'WORDPRESS_DB_PASSWORD' ],
				[ 'name' => 'USER_ID' ],
				[ 'name' => 'GROUP_ID' ],
				[ 'name' => 'VIRTUAL_HOST' ],
				[ 'name' => 'NEWRELIC_APPNAME=${VIRTUAL_HOST}' ],
				[ 'name' => 'NEWRELIC_LICENSE_KEY' ],
			],
		];

		$php['sysctls'] = sysctl_parameters();

		$php['networks'] = [
			'net' => [
				[
					'name'    => 'site-network',
					'aliases' => [
						'alias' => [
							'name' => '${VIRTUAL_HOST}_php',
						],
					],
				],
			],
		];

		$global_network = array_intersect( [ GLOBAL_DB, GLOBAL_REDIS ], $filters );
		if ( ! empty ( $global_network ) ) {
			$php['networks']['net'][]          = [ 'name' => 'global-backend-network' ];
			$network['enable_backend_network'] = true;
		}

		// nginx configuration.
		$nginx['service_name']               = [ 'name' => 'nginx' ];
		$nginx['image']                      = [ 'name' => 'easyengine/nginx:' . $img_versions['easyengine/nginx'] ];
		$nginx['depends_on']['dependency'][] = [ 'name' => 'php' ];
		$nginx['restart']                    = $restart_default;

		if ( ! empty( $filters['alias_domains'] ) ) {
			$v_host = in_array( 'subdom', $filters, true ) ? 'VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST}' : 'VIRTUAL_HOST=${VIRTUAL_HOST}';
			$v_host .= ',' . $filters['alias_domains'];
		} else {
			$v_host = in_array( 'subdom', $filters, true ) ? 'VIRTUAL_HOST=${VIRTUAL_HOST},*.${VIRTUAL_HOST}' : 'VIRTUAL_HOST';
		}

		$nginx['environment'] = [
			'env' => [
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/' ],
				[ 'name' => 'HSTS=off' ],
			],
		];

		if ( ! empty( $filters['alias_domains'] ) ) {
			$nginx['environment']['env'][] = [ 'name' => 'CERT_NAME=${VIRTUAL_HOST}' ];
		}

		$ssl_policy = get_ssl_policy();
		if ( ! empty( $filters['nohttps'] ) && $filters['nohttps'] ) {
			$nginx['environment']['env'][] = [ 'name' => 'HTTPS_METHOD=nohttps' ];
		} elseif ( 'Mozilla-Modern' !== $ssl_policy ) {
			$nginx['environment']['env'][] = [ 'name' => "SSL_POLICY=$ssl_policy" ];
		}

		$nginx['volumes'] = [
			'vol' => \EE_DOCKER::get_mounting_volume_array( $volumes['nginx'] ),
		];
		$nginx['labels']  = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];

		$nginx['sysctls'] = sysctl_parameters();

		$nginx['networks'] = [
			'net' => [
				[ 'name' => 'global-frontend-network' ],
				[ 'name' => 'site-network' ],
			],
		];
		if ( in_array( GLOBAL_REDIS, $filters, true ) ) {
			$nginx['networks']['net'][]        = [ 'name' => 'global-backend-network' ];
			$network['enable_backend_network'] = true;
		}

		// mailhog configuration.
		$mailhog['service_name'] = [ 'name' => 'mailhog' ];
		$mailhog['image']        = [ 'name' => 'easyengine/mailhog:' . $img_versions['easyengine/mailhog'] ];
		$mailhog['restart']      = $restart_default;
		$mailhog['command']      = [ 'name' => '["-invite-jim=false"]' ];
		$mailhog['environment']  = [
			'env' => [
				[ 'name' => $v_host ],
				[ 'name' => 'VIRTUAL_PATH=/ee-admin/mailhog/' ],
				[ 'name' => 'VIRTUAL_PORT=8025' ],
			],
		];
		$mailhog['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$mailhog['networks']     = [
			'net' => [
				[ 'name' => 'site-network' ],
				[ 'name' => 'global-frontend-network' ],
			],
		];

		// postfix configuration.
		$postfix['service_name'] = [ 'name' => 'postfix' ];
		$postfix['image']        = [ 'name' => 'easyengine/postfix:' . $img_versions['easyengine/postfix'] ];
		$postfix['hostname']     = [ 'name' => '${VIRTUAL_HOST}' ];
		$postfix['restart']      = $restart_default;
		$postfix['environment']  = [
			'env' => [
				[ 'name' => 'RELAY_HOST' ],
				[ 'name' => 'REPLY_EMAIL' ],
			],
		];
		$postfix['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$postfix['volumes']      = [
			'vol' => \EE_DOCKER::get_mounting_volume_array( $volumes['postfix'] ),
		];
		$postfix['networks']     = $network_default;

		// redis configuration.
		$redis['service_name'] = [ 'name' => 'redis' ];
		$redis['image']        = [ 'name' => 'easyengine/redis:' . $img_versions['easyengine/redis'] ];
		$redis['labels']       = [
			'label' => [
				'name' => 'io.easyengine.site=${VIRTUAL_HOST}',
			],
		];
		$redis['sysctls']      = sysctl_parameters();
		$redis['networks']     = $network_default;

		$base[] = $php;
		$base[] = $nginx;
		$base[] = $mailhog;
		$base[] = $postfix;

		if ( in_array( 'redis', $filters, true ) ) {
			$base[] = $redis;
		}

		$external_volumes = [
			'external_vols' => [
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'htdocs' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'config_nginx' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'config_php' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'log_php' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'log_nginx' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'data_postfix' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'ssl_postfix' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'config_postfix' ],
				[ 'prefix' => GLOBAL_NEWRELIC_DAEMON, 'ext_vol_name' => 'newrelic_sock' ],
			],
		];

		if ( in_array( 'db', $filters, true ) ) {
			$base[]                            = $db;
			$external_volumes['external_vols'] = array_merge( $external_volumes['external_vols'], [
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'db_data' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'db_conf' ],
				[ 'prefix' => $filters['site_prefix'], 'ext_vol_name' => 'db_logs' ],
			] );
		} else {
			$network['enable_backend_network'] = true;
		}

		$binding = [
			'services' => $base,
			'network'  => $network,
		];

		if ( ! IS_DARWIN ) {
			$binding['created_volumes'] = $external_volumes;
		}

		$docker_compose_yml = mustache_render( SITE_WP_TEMPLATE_ROOT . '/docker-compose.mustache', $binding );

		return $docker_compose_yml;
	}
}
