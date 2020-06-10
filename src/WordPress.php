<?php

declare( ticks=1 );

namespace EE\Site\Type;

use EE;
use EE\Model\Site;
use EE\Model\Site_Meta;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\get_public_dir;
use function EE\Utils\get_flag_value;
use function EE\Utils\trailingslashit;
use function EE\Utils\get_value_if_flag_isset;

/**
 * Adds `wp` site type to `ee site` command.
 *
 * @package ee-cli
 */
class WordPress extends EE_Site_Command {

	/**
	 * @var string $cache_type Type of caching being used.
	 */
	private $cache_type;

	/**
	 * @var int $level The level of creation in progress. Essential for rollback in case of failure.
	 */
	private $level;

	/**
	 * @var object $logger Object of logger.
	 */
	private $logger;

	/**
	 * @var string $locale Language to install WordPress in.
	 */
	private $locale;

	/**
	 * @var bool $skip_install To skip installation of WordPress.
	 */
	private $skip_install;

	/**
	 * @var bool $skip_status_check To skip site status check pre-installation.
	 */
	private $skip_status_check;

	/**
	 * @var bool $force To reset remote database.
	 */
	private $force;

	/**
	 * @var string $vip_go_mu_plugins WordPress VIP Go mu-plugins repo.
	 */
	private $vip_go_mu_plugins = 'https://github.com/Automattic/vip-go-mu-plugins-built';

	/**
	 * @var string $vip_go_mu_plugins WordPress VIP Go mu-plugins repo.
	 */
	private $vip_go_skeleton = 'https://github.com/Automattic/vip-go-skeleton.git';

	/**
	 * @var bool $is_vip To check if site is setup for vip.
	 */
	private $is_vip = false;

	public function __construct() {

		parent::__construct();
		$this->level  = 0;
		$this->logger = \EE::get_file_logger()->withName( 'site_wp_command' );

		$this->site_data['site_type'] = 'wp';
	}

	/**
	 * Runs the standard WordPress Site installation.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--cache]
	 * : Use redis cache for WordPress.
	 *
	 * [--vip]
	 * : Create WordPress VIP GO site using your vip repo which contains wp-content dir. Default it will use skeleton repo.
	 * ---
	 * default: https://github.com/Automattic/vip-go-skeleton.git
	 * ---
	 *
	 * [--mu=<subdir|subdom>]
	 * : Specify WordPress Multi-site type.
	 *
	 * [--alias-domains=<domains>]
	 * : Comma separated list of alias domains for the site.
	 *
	 * [--title=<title>]
	 * : Title of your site.
	 *
	 * [--admin-user=<admin-user>]
	 * : Username of the administrator.
	 *
	 * [--admin-pass=<admin-pass>]
	 * : Password for the the administrator.
	 *
	 * [--admin-email=<admin-email>]
	 * : E-Mail of the administrator.
	 *
	 * [--local-db]
	 * : Create separate db container instead of using global db.
	 *
	 * [--with-local-redis]
	 * : Enable cache with local redis container.
	 *
	 * [--public-dir]
	 * : Set custom source directory for site inside htdocs.
	 *
	 * [--php=<php-version>]
	 * : PHP version for site. Currently only supports PHP 5.6, 7.0, 7.2, 7.3, 7.4 and latest.
	 * ---
	 * default: latest
	 * options:
	 *	- 5.6
	 *	- 7.0
	 *	- 7.2
	 *	- 7.3
	 *	- 7.4
	 *	- latest
	 * ---
	 *
	 * [--git]
	 * : Create your site using your git repo content. All content will be cloned into wp-content directory.
	 *
	 * [--dbname=<dbname>]
	 * : Set the database name.
	 *
	 * [--dbuser=<dbuser>]
	 * : Set the database user.
	 *
	 * [--dbpass=<dbpass>]
	 * : Set the database password.
	 *
	 * [--dbhost=<dbhost>]
	 * : Set the database host. Pass value only when remote dbhost is required.
	 *
	 * [--dbprefix=<dbprefix>]
	 * : Set the database table prefix.
	 *
	 * [--dbcharset=<dbcharset>]
	 * : Set the database charset.
	 * ---
	 * default: utf8mb4
	 * ---
	 *
	 * [--dbcollate=<dbcollate>]
	 * : Set the database collation.
	 *
	 * [--skip-check]
	 * : If set, the database connection is not checked.
	 *
	 * [--version=<version>]
	 * : Select which WordPress version you want to download. Accepts a version number, ‘latest’ or ‘nightly’.
	 *
	 * [--skip-content]
	 * : Download WP without the default themes and plugins.
	 *
	 * [--skip-install]
	 * : Skips wp-core install.
	 *
	 * [--skip-status-check]
	 * : Skips site status check.
	 *
	 * [--ssl]
	 * : Enables ssl on site.
	 * ---
	 * options:
	 *      - le
	 *      - self
	 *      - inherit
	 *      - custom
	 * ---
	 *
	 * [--ssl-key=<ssl-key-path>]
	 * : Path to the SSL key file.
	 *
	 * [--ssl-crt=<ssl-crt-path>]
	 * : Path to the SSL crt file.
	 *
	 * [--wildcard]
	 * : Gets wildcard SSL.
	 * 
	 * [--proxy-cache=<on-or-off>]
	 * : Enable or disable proxy cache on site.
	 * ---
	 * default: off
	 * options:
	 *  - on
	 *  - off
	 * ---
	 *
	 * [--proxy-cache-max-size=<size-in-m-or-g>]
	 * : Max size for proxy-cache.
	 *
	 * [--proxy-cache-max-time=<time-in-s-or-m>]
	 * : Max time for proxy cache to last.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * [--force]
	 * : Resets the remote database if it is not empty.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create WordPress site
	 *     $ ee site create example.com --type=wp
	 *
	 *     # Create WordPress multisite subdir site
	 *     $ ee site create example.com --type=wp --mu=subdir
	 *
	 *     # Create WordPress multisite subdom site
	 *     $ ee site create example.com --type=wp --mu=subdom
	 *
	 *     # Create WordPress site with ssl from letsencrypt
	 *     $ ee site create example.com --type=wp --ssl=le
	 *
	 *     # Create WordPress site with wildcard ssl
	 *     $ ee site create example.com --type=wp --ssl=le --wildcard
	 *
	 *     # Create WordPress site with self signed certificate
	 *     $ ee site create example.com --type=wp --ssl=self
	 *
	 *     # Create WordPress site with remote database
	 *     $ ee site create example.com --type=wp --dbhost=localhost --dbuser=username --dbpass=password
	 *
	 *     # Create WordPress site with custom site title, locale, admin user, admin email and admin password
	 *     $ ee site create example.com --type=wp --title=easyengine  --locale=nl_NL --admin-email=easyengine@example.com --admin-user=easyengine --admin-pass=easyengine
	 *
	 *     # Create WordPress site with custom source directory inside htdocs ( SITE_ROOT/app/htdocs/current )
	 *     $ ee site create example.com --type=wp --public-dir=current
	 *
	 *     # Create WordPress site with custom ssl certs
	 *     $ ee site create example.com --ssl=custom  --ssl-key='/path/to/example.com.key' --ssl-crt='/path/to/example.com.crt'
	 *
	 *     # Create WordPress site using repository content, content will be clone into wp-content directory
	 *     $ ee site create example.com ---type=wp --git=git@github.com:example/examplesite.git
	 *
	 */
	public function create( $args, $assoc_args ) {

		$this->check_site_count();
		\EE\Utils\delem_log( 'site create start' );
		$this->logger->debug( 'args:', $args );
		$this->logger->debug( 'assoc_args:', empty( $assoc_args ) ? array( 'NULL' ) : $assoc_args );
		$this->site_data['site_url'] = strtolower( \EE\Utils\remove_trailing_slash( $args[0] ) );

		$mu = \EE\Utils\get_flag_value( $assoc_args, 'mu' );
		$this->site_data['app_sub_type'] = $mu ?? 'wp';

		EE::log( 'Starting site creation.' );

		if ( isset( $assoc_args['mu'] ) && ! in_array( $mu, [ 'subdom', 'subdir' ], true ) ) {
			\EE::error( "Unrecognized multi-site parameter: $mu. Only `--mu=subdom` and `--mu=subdir` are supported." );
		}

		$vip_wp_content_repo = \EE\Utils\get_flag_value( $assoc_args, 'vip' );

		if ( ! empty( $vip_wp_content_repo ) && is_bool( $vip_wp_content_repo ) ) {
			$vip_wp_content_repo = $this->vip_go_skeleton;
			\EE::log( 'VIP Skeleton repo will be used for wp-content directory: ' . $vip_wp_content_repo );
		}

		if ( ! empty( $vip_wp_content_repo ) ) {
			$this->check_repo_access( $vip_wp_content_repo );
		}

		if ( Site::find( $this->site_data['site_url'] ) ) {
			\EE::error( sprintf( "Site %1\$s already exists. If you want to re-create it please delete the older one using:\n`ee site delete %1\$s`", $this->site_data['site_url'] ) );
		}

		$this->site_data['site_fs_path']       = WEBROOT . $this->site_data['site_url'];
		$this->cache_type                      = \EE\Utils\get_flag_value( $assoc_args, 'cache' );
		$wildcard_flag                         = \EE\Utils\get_flag_value( $assoc_args, 'wildcard' );
		$this->site_data['site_ssl_wildcard']  = 'subdom' === $this->site_data['app_sub_type'] || $wildcard_flag ? true : false;
		$this->site_data['php_version']        = \EE\Utils\get_flag_value( $assoc_args, 'php', 'latest' );
		$this->site_data['app_admin_url']      = \EE\Utils\get_flag_value( $assoc_args, 'title', $this->site_data['site_url'] );
		$this->site_data['app_admin_username'] = \EE\Utils\get_flag_value( $assoc_args, 'admin-user', \EE\Utils\random_name_generator() );
		$this->site_data['app_admin_password'] = \EE\Utils\get_flag_value( $assoc_args, 'admin-pass', '' );
		$this->site_data['db_name']            = \EE\Utils\get_flag_value( $assoc_args, 'dbname', str_replace( [ '.', '-' ], '_', $this->site_data['site_url'] ) );
		$this->site_data['db_host']            = \EE\Utils\get_flag_value( $assoc_args, 'dbhost', GLOBAL_DB );
		$this->site_data['db_port']            = '3306';
		$this->site_data['db_user']            = \EE\Utils\get_flag_value( $assoc_args, 'dbuser', $this->create_site_db_user( $this->site_data['site_url'] ) );
		$this->site_data['db_password']        = \EE\Utils\get_flag_value( $assoc_args, 'dbpass', \EE\Utils\random_password() );
		$alias_domains                         = \EE\Utils\get_flag_value( $assoc_args, 'alias-domains', '' );
		$this->site_data['proxy-cache']        = \EE\Utils\get_flag_value( $assoc_args, 'proxy-cache' );
		$this->locale                          = \EE\Utils\get_flag_value( $assoc_args, 'locale', \EE::get_config( 'locale' ) );
		$local_cache                           = \EE\Utils\get_flag_value( $assoc_args, 'with-local-redis' );
		$this->site_data['cache_host']         = '';
		if ( 'on' === $this->site_data['proxy-cache'] ) {
			$this->cache_type = true;
		}
		if ( $this->cache_type ) {
			$this->site_data['cache_host'] = $local_cache ? 'redis' : 'global-redis';
		}

		if ( empty( $this->site_data['app_admin_password'] ) ) {
			$this->site_data['app_admin_password'] = \EE\Utils\random_password( 18 );
		} else {
			$pass_error_msg = [];
			if ( strlen( $this->site_data['app_admin_password'] ) < 8 ) {
				$pass_error_msg[] = "Password too short! Must be at least 8 characters long.";
			}

			if ( ! preg_match( "#[0-9]+#", $this->site_data['app_admin_password'] ) ) {
				$pass_error_msg[] = "Password must include at least one number!";
			}

			if ( ! preg_match( "#[a-zA-Z]+#", $this->site_data['app_admin_password'] ) ) {
				$pass_error_msg[] = "Password must include at least one letter!";
			}

			if ( ! empty( $pass_error_msg ) ) {
				$final_error_msg = 'Issues found in input password: `' . $this->site_data['app_admin_password'] . "`\n\t";
				foreach ( $pass_error_msg as $err_msg ) {
					$final_error_msg .= '* ' . $err_msg . "\n\t";
				}
				EE::error( $final_error_msg );
			}
		}

		$this->site_data['site_container_fs_path'] = get_public_dir( $assoc_args );
		$this->site_data['site_ssl']               = get_value_if_flag_isset( $assoc_args, 'ssl', [ 'le', 'self', 'inherit', 'custom' ], 'le' );
		if ( 'custom' === $this->site_data['site_ssl'] ) {
			try {
				$this->validate_site_custom_ssl( get_flag_value( $assoc_args, 'ssl-key' ), get_flag_value( $assoc_args, 'ssl-crt' ) );
			} catch ( \Exception $e ) {
				$this->catch_clean( $e );
			}
		}

		$this->site_data['alias_domains'] = ( 'subdom' === $this->site_data['app_sub_type'] ) ? $this->site_data['site_url'] . ',*.' . $this->site_data['site_url'] : $this->site_data['site_url'];
		$this->site_data['alias_domains'] .= ',';
		if ( ! empty( $alias_domains ) ) {
			$comma_seprated_domains = explode( ',', $alias_domains );
			foreach ( $comma_seprated_domains as $domain ) {
				$trimmed_domain = trim( $domain );
				if ( filter_var( $trimmed_domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
					$this->site_data['alias_domains'] .= $trimmed_domain . ',';
				}
			}
		}
		$this->site_data['alias_domains'] = substr( $this->site_data['alias_domains'], 0, - 1 );

		$supported_php_versions = [ 5.6, 7.0, 7.2, 7.3, 7.4, 'latest' ];
		if ( ! in_array( $this->site_data['php_version'], $supported_php_versions ) ) {
			$old_version = $this->site_data['php_version'];
			$floor       = (int) floor( $this->site_data['php_version'] );
			if ( 5 === $floor ) {
				$this->site_data['php_version'] = 5.6;
			} elseif ( 7 === $floor ) {
				$this->site_data['php_version'] = 7.4;
				$old_version .= ' yet';
			} else {
				EE::error( 'Unsupported PHP version: ' . $this->site_data['php_version'] );
			}
			\EE::confirm( sprintf( 'EEv4 does not support PHP %s. Continue with PHP %s?', $old_version, $this->site_data['php_version'] ), $assoc_args );
		}

		$this->site_data['php_version'] = ( 7.4 === (double) $this->site_data['php_version'] ) ? 'latest' : $this->site_data['php_version'];

		if ( \EE\Utils\get_flag_value( $assoc_args, 'local-db' ) ) {
			$this->site_data['db_host'] = 'db';
		}
		$this->site_data['db_root_password'] = ( 'db' === $this->site_data['db_host'] ) ? \EE\Utils\random_password() : '';

		\EE\Service\Utils\nginx_proxy_check();

		if ( $this->cache_type && ! $local_cache ) {
			\EE\Service\Utils\init_global_container( GLOBAL_REDIS );
		}

		if ( GLOBAL_DB === $this->site_data['db_host'] ) {
			\EE\Service\Utils\init_global_container( GLOBAL_DB );
			try {
				$user_data = \EE\Site\Utils\create_user_in_db( GLOBAL_DB, $this->site_data['db_name'], $this->site_data['db_user'], $this->site_data['db_password'] );
				if ( ! $user_data ) {
					throw new \Exception( sprintf( 'Could not create user %s. Please check logs.', $this->site_data['db_user'] ) );
				}
			} catch ( \Exception $e ) {
				$this->catch_clean( $e );
			}
			$this->site_data['db_name']     = $user_data['db_name'];
			$this->site_data['db_user']     = $user_data['db_user'];
			$this->site_data['db_password'] = $user_data['db_pass'];
		} elseif ( 'db' !== $this->site_data['db_host'] ) {
			// If user wants to connect to remote database.
			if ( ! isset( $assoc_args['dbuser'] ) || ! isset( $assoc_args['dbpass'] ) ) {
				\EE::error( '`--dbuser` and `--dbpass` are required for remote db host.' );
			}
			$arg_host_port              = explode( ':', $this->site_data['db_host'] );
			$this->site_data['db_host'] = $arg_host_port[0];
			$this->site_data['db_port'] = empty( $arg_host_port[1] ) ? '3306' : $arg_host_port[1];
		}

		$default_email                      = \EE::get_runner()->config['wp-mail'] ?? 'admin@' . $this->site_data['site_url'];
		$this->site_data['app_admin_email'] = \EE\Utils\get_flag_value( $assoc_args, 'admin-email', strtolower( $default_email ) );
		$this->skip_install                 = \EE\Utils\get_flag_value( $assoc_args, 'skip-install' );
		$this->skip_status_check            = \EE\Utils\get_flag_value( $assoc_args, 'skip-status-check' );
		$this->force                        = \EE\Utils\get_flag_value( $assoc_args, 'force' );

		if ( 'inherit' === $this->site_data['site_ssl'] && ( 'subdom' === $mu || $this->site_data['site_ssl_wildcard'] ) ) {
			\EE::error( '--wildcard or --mu=subdom flag can not be passed together with --ssl=inherit flag.' );
		}

		\EE::log( 'Configuring project.' );

		// Check if git repo URL was provided.
		$this->git_repo = \EE\Utils\get_flag_value( $assoc_args, 'git', '' );

		// Only allow git creation if --vip was not provided.
		if ( NULL === $vip_wp_content_repo && ! empty( $this->git_repo ) ) {
			$this->is_git_repo = true;
		}

		$this->create_site( $assoc_args );
		\EE\Utils\delem_log( 'site create end' );
	}

	/**
	 * Enable object cache.
	 */
	private function enable_object_cache() {

		$redis_host               = $this->site_data['cache_host'];
		$redis_plugin_constant    = 'docker-compose exec --user=\'www-data\' php wp config set --type=variable redis_server "array(\'host\'=> \'' . $redis_host . '\',\'port\'=> 6379,)" --raw';
		$activate_wp_redis_plugin = "docker-compose exec --user='www-data' php wp plugin install wp-redis --activate";
		$enable_redis_cache       = "docker-compose exec --user='www-data' php wp redis enable";

		$this->docker_compose_exec( $redis_plugin_constant, 'Unable to download or activate wp-redis plugin.' );
		$this->docker_compose_exec( $activate_wp_redis_plugin, 'Unable to download or activate wp-redis plugin.' );
		$this->docker_compose_exec( $enable_redis_cache, 'Unable to enable object cache' );
	}

	/**
	 * Enable page cache.
	 */
	private function enable_page_cache() {
		$activate_nginx_helper = 'docker-compose exec --user=\'www-data\' php wp plugin install nginx-helper --activate';
		$nginx_helper_fail_msg = 'Unable to download or activate nginx-helper plugin properly.';
		$page_cache_key_prefix = $this->site_data['site_url'] . '_page:';
		$obj_cache_key_prefix  = $this->site_data['site_url'] . '_obj:';

		$redis_host    = $this->site_data['cache_host'];
		$wp_cli_params = ( 'wp' === $this->site_data['app_sub_type'] ) ? 'option update' : 'network meta update 1';

		$plugin_data = sprintf( '{
				"log_level":"INFO",
				"log_filesize":5,
				"enable_purge":1,
				"enable_map":0,
				"enable_log":0,
				"enable_stamp":0,
				"purge_homepage_on_new":1,
				"purge_homepage_on_edit":1,
				"purge_homepage_on_del":1,
				"purge_archive_on_new":1,
				"purge_archive_on_edit":0,
				"purge_archive_on_del":0,
				"purge_archive_on_new_comment":0,
				"purge_archive_on_deleted_comment":0,
				"purge_page_on_mod":1,
				"purge_page_on_new_comment":1,
				"purge_page_on_deleted_comment":1,
				"cache_method":"enable_redis",
				"purge_method":"get_request",
				"redis_hostname":"%s",
				"redis_port":"6379",
				"redis_prefix":"%s"
			}',
			$redis_host,
			$page_cache_key_prefix
		);

		$add_hostname_constant = "docker-compose exec --user='www-data' php wp config set RT_WP_NGINX_HELPER_REDIS_HOSTNAME $redis_host --add=true --type=constant";
		$add_port_constant     = "docker-compose exec --user='www-data' php wp config set RT_WP_NGINX_HELPER_REDIS_PORT 6379 --add=true --type=constant";
		$add_prefix_constant   = "docker-compose exec --user='www-data' php wp config set RT_WP_NGINX_HELPER_REDIS_PREFIX $page_cache_key_prefix --add=true --type=constant";
		$add_cache_key_salt    = "docker-compose exec --user='www-data' php wp config set WP_CACHE_KEY_SALT $obj_cache_key_prefix --add=true --type=constant";
		$add_redis_maxttl      = "docker-compose exec --user='www-data' php wp config set WP_REDIS_MAXTTL 14400 --add=true --type=constant";
		$add_plugin_data       = "docker-compose exec --user='www-data' php wp $wp_cli_params rt_wp_nginx_helper_options '$plugin_data' --format=json";

		$this->docker_compose_exec( $add_hostname_constant, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_port_constant, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_prefix_constant, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_cache_key_salt, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $activate_nginx_helper, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_plugin_data, $nginx_helper_fail_msg );
		$this->docker_compose_exec( $add_redis_maxttl, $nginx_helper_fail_msg );
	}

	/**
	 *  Execute command with fail msg.
	 *
	 * @param string $command  Command to execute.
	 * @param string $fail_msg failure message.
	 */
	private function docker_compose_exec( $command, $fail_msg = '' ) {
		if ( empty( $command ) ) {
			return;
		}
		if ( ! \EE::exec( $command ) ) {
			\EE::warning( $fail_msg );
		}
	}

	/**
	 * Creates database user for a site
	 *
	 * @param string $site_url URL of site.
	 *
	 * @return string Generated db user.
	 */
	private function create_site_db_user( string $site_url ): string {
		if ( strlen( $site_url ) > 53 ) {
			$site_url = substr( $site_url, 0, 53 );
		}

		return $site_url . '-' . \EE\Utils\random_password( 6 );
	}

	/**
	 * Display all the relevant site information, credentials and useful links.
	 *
	 * [<site-name>]
	 * : Name of the website whose info is required.
	 *
	 * ## EXAMPLES
	 *
	 *     # Display site info
	 *     $ ee site info example.com
	 */
	public function info( $args, $assoc_args ) {

		\EE\Utils\delem_log( 'site info start' );
		if ( ! isset( $this->site_data['site_url'] ) ) {
			$args             = auto_site_name( $args, 'wp', __FUNCTION__ );
			$this->site_data  = get_site_info( $args, false );
			$this->cache_type = $this->site_data['cache_nginx_fullpage'];
		}
		$ssl    = $this->site_data['site_ssl'] ? 'Enabled' : 'Not Enabled';
		$prefix = ( $this->site_data['site_ssl'] ) ? 'https://' : 'http://';
		$info   = [ [ 'Site', $prefix . $this->site_data['site_url'] ] ];
		if ( ! empty( $this->site_data['admin_tools'] ) ) {
			$info[] = [ 'Access admin-tools', $prefix . $this->site_data['site_url'] . '/ee-admin/' ];
		}
		$info[] = [ 'Site Root', $this->site_data['site_fs_path'] ];
		$info[] = [ 'Site Title', $this->site_data['app_admin_url'] ];
		if ( ! empty( $this->site_data['app_admin_username'] ) && ! $this->skip_install ) {
			$info[] = [ 'WordPress Username', $this->site_data['app_admin_username'] ];
			$info[] = [ 'WordPress Password', $this->site_data['app_admin_password'] ];
		}
		$alias_domains            = implode( ',', array_diff( explode( ',', $this->site_data['alias_domains'] ), [ $this->site_data['site_url'] ] ) );
		$info_alias_domains_value = empty( $alias_domains ) ? 'None' : $alias_domains;
		$info[]                   = [ 'Alias Domains', $info_alias_domains_value ];

		$info[] = [ 'DB Host', $this->site_data['db_host'] ];
		if ( ! empty( $this->site_data['db_root_password'] ) ) {
			$info[] = [ 'DB Root Password', $this->site_data['db_root_password'] ];
		}
		$info[] = [ 'DB Name', $this->site_data['db_name'] ];
		$info[] = [ 'DB User', $this->site_data['db_user'] ];
		$info[] = [ 'DB Password', $this->site_data['db_password'] ];
		$info[] = [ 'E-Mail', $this->site_data['app_admin_email'] ];
		$info[] = [ 'SSL', $ssl ];

		if ( $this->site_data['site_ssl'] ) {
			$info[] = [ 'SSL Wildcard', $this->site_data['site_ssl_wildcard'] ? 'Yes' : 'No' ];
		}
		$info[] = [ 'Cache', $this->cache_type ? 'Enabled' : 'None' ];

		\EE\Utils\format_table( $info );

		\EE\Utils\delem_log( 'site info end' );
	}

	/**
	 * Function to configure site and copy all the required files.
	 */
	private function configure_site_files() {

		$site_conf_dir           = $this->site_data['site_fs_path'] . '/config';
		$site_conf_env           = $this->site_data['site_fs_path'] . '/.env';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/conf.d/main.conf';
		$site_php_ini            = $site_conf_dir . '/php/php/conf.d/custom.ini';
		$custom_conf_dest        = $site_conf_dir . '/nginx/custom/user.conf';
		$custom_conf_source      = SITE_WP_TEMPLATE_ROOT . '/config/nginx/user.conf.mustache';
		$process_user            = posix_getpwuid( posix_geteuid() );

		\EE::log( 'Creating WordPress site ' . $this->site_data['site_url'] );
		\EE::log( 'Copying configuration files.' );

		$server_name = implode( ' ', explode( ',', $this->site_data['alias_domains'] ) );

		$default_conf_content = $this->generate_default_conf( $this->site_data['app_sub_type'], $this->cache_type, $server_name );
		$local                = ( 'db' === $this->site_data['db_host'] ) ? true : false;

		$db_host  = $local ? $this->site_data['db_host'] : $this->site_data['db_host'] . ':' . $this->site_data['db_port'];
		$env_data = [
			'local'         => $local,
			'virtual_host'  => $this->site_data['site_url'],
			'root_password' => $this->site_data['db_root_password'],
			'database_name' => $this->site_data['db_name'],
			'database_user' => $this->site_data['db_user'],
			'user_password' => $this->site_data['db_password'],
			'wp_db_host'    => $db_host,
			'wp_db_user'    => $this->site_data['db_user'],
			'wp_db_name'    => $this->site_data['db_name'],
			'wp_db_pass'    => $this->site_data['db_password'],
			'user_id'       => $process_user['uid'],
			'group_id'      => $process_user['gid'],
		];

		$custom_ini      = '5.6' === (string) $this->site_data['php_version'] ? 'php.ini-56.mustache' : 'php.ini.mustache';
		$env_content     = \EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/.env.mustache', $env_data );
		$php_ini_content = file_get_contents( SITE_WP_TEMPLATE_ROOT . '/config/php-fpm/' . $custom_ini );

		try {
			$this->dump_docker_compose_yml( [ 'nohttps' => true ] );
			$this->fs->dumpFile( $site_conf_env, $env_content );
			if ( ! IS_DARWIN ) {
				\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], [ 'nginx', 'postfix' ] );
			}
			\EE\Site\Utils\set_postfix_files( $this->site_data['site_url'], $this->site_data['site_fs_path'] . '/services' );
			$this->fs->dumpFile( $site_nginx_default_conf, $default_conf_content );
			$this->fs->copy( $custom_conf_source, $custom_conf_dest );
			$this->fs->remove( $this->site_data['site_fs_path'] . '/app/html' );
			$this->fs->dumpFile( $site_php_ini, $php_ini_content );
			if ( IS_DARWIN ) {
				if ( 'db' === $this->site_data['db_host'] ) {
					$db_conf_file = $this->site_data['site_fs_path'] . '/services/mariadb/conf/my.cnf';
					$this->fs->copy( SITE_WP_TEMPLATE_ROOT . '/my.cnf.mustache', $db_conf_file );
				}
				\EE\Site\Utils\start_site_containers( $this->site_data['site_fs_path'], [ 'nginx', 'php', 'postfix' ] );
			} else {
				\EE\Site\Utils\restart_site_containers( $this->site_data['site_fs_path'], [ 'nginx', 'php' ] );
			}
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Generate and place docker-compose.yml file.
	 *
	 * @param array $additional_filters Filters to alter docker-compose file.
	 *
	 * @ignorecommand
	 */
	public function dump_docker_compose_yml( $additional_filters = [] ) {

		$site_conf_dir           = $this->site_data['site_fs_path'] . '/config';
		$site_nginx_default_conf = $site_conf_dir . '/nginx/conf.d/main.conf';
		$site_php_ini            = $site_conf_dir . '/php/php/conf.d/custom.ini';

		$volumes = [
			'nginx'   => [
				[
					'name'            => 'htdocs',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/app',
					'container_path'  => '/var/www',
				],
				[
					'name'            => 'config_nginx',
					'path_to_symlink' => dirname( dirname( $site_nginx_default_conf ) ),
					'container_path'  => '/usr/local/openresty/nginx/conf',
					'skip_darwin'     => true,
				],
				[
					'name'            => 'config_nginx',
					'path_to_symlink' => $site_nginx_default_conf,
					'container_path'  => '/usr/local/openresty/nginx/conf/conf.d/main.conf',
					'skip_linux'      => true,
					'skip_volume'     => true,
				],
				[
					'name'            => 'log_nginx',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/logs/nginx',
					'container_path'  => '/var/log/nginx',
				],
			],
			'php'     => [
				[
					'name'            => 'htdocs',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/app',
					'container_path'  => '/var/www',
				],
				[
					'name'            => 'config_php',
					'path_to_symlink' => $site_conf_dir . '/php',
					'container_path'  => '/usr/local/etc',
					'skip_darwin'     => true,
				],
				[
					'name'            => 'config_php',
					'path_to_symlink' => $site_php_ini,
					'container_path'  => '/usr/local/etc/php/php/conf.d/custom.ini',
					'skip_linux'      => true,
					'skip_volume'     => true,
				],
				[
					'name'            => 'log_php',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/logs/php',
					'container_path'  => '/var/log/php',
				],
			],
			'postfix' => [
				[
					'name'            => '/dev/log',
					'path_to_symlink' => '/dev/log',
					'container_path'  => '/dev/log',
					'skip_volume'     => true,
					'skip_darwin'     => true,
				],
				[
					'name'            => 'data_postfix',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/postfix/spool',
					'container_path'  => '/var/spool/postfix',
				],
				[
					'name'            => 'ssl_postfix',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/postfix/ssl',
					'container_path'  => '/etc/ssl/postfix',
				],
				[
					'name'            => 'config_postfix',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/config/postfix',
					'container_path'  => '/etc/postfix',
					'skip_darwin'     => true,
				],
			],
		];

		if ( 'db' === $this->site_data['db_host'] ) {
			$volumes['db'] = [
				[
					'name'            => 'db_data',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/mariadb/data',
					'container_path'  => '/var/lib/mysql',
				],
				[
					'name'            => 'db_conf',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/mariadb/conf',
					'container_path'  => '/etc/mysql',
					'skip_darwin'     => true,
				],
				[
					'name'            => 'db_conf',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/mariadb/conf/my.cnf',
					'container_path'  => '/etc/mysql/my.cnf',
					'skip_linux'      => true,
					'skip_volume'     => true,
				],
				[
					'name'            => 'db_logs',
					'path_to_symlink' => $this->site_data['site_fs_path'] . '/services/mariadb/logs',
					'container_path'  => '/var/log/mysql',
				],
			];
		}

		if ( ! IS_DARWIN && empty( \EE_DOCKER::get_volumes_by_label( $this->site_data['site_url'] ) ) ) {
			foreach ( $volumes as $volume ) {
				\EE_DOCKER::create_volumes( $this->site_data['site_url'], $volume );
			}
		}

		// Add newrelic volume later on as it is not required to be created. There is a global volume for it.
		$volumes['php'][] = [
			'name'            => 'newrelic_sock',
			'path_to_symlink' => '',
			'container_path'  => '/run/newrelic',
			'skip_darwin'     => true,
		];

		$site_docker_yml = $this->site_data['site_fs_path'] . '/docker-compose.yml';

		$filter                  = [];
		$filter[]                = $this->site_data['app_sub_type'];
		$filter[]                = $this->site_data['cache_host'];
		$filter[]                = $this->site_data['db_host'];
		$filter['is_ssl']        = $this->site_data['site_ssl'];
		$filter['site_prefix']   = \EE_DOCKER::get_docker_style_prefix( $this->site_data['site_url'] );
		$filter['php_version']   = ( string ) $this->site_data['php_version'];
		$filter['alias_domains'] = implode( ',', array_diff( explode( ',', $this->site_data['alias_domains'] ), [ $this->site_data['site_url'] ] ) );
		$site_docker             = new Site_WP_Docker();

		foreach ( $additional_filters as $key => $addon_filter ) {
			$filter[ $key ] = $addon_filter;
		}

		$docker_compose_content = $site_docker->generate_docker_compose_yml( $filter, $volumes );
		$this->fs->dumpFile( $site_docker_yml, $docker_compose_content );
	}


	/**
	 * Function to generate main.conf from mustache templates.
	 *
	 * @param string $site_type   Type of site (subdom, subdir etc..).
	 * @param boolean $cache_type Cache enabled or not.
	 * @param string $server_name Name of server to use in virtual_host.
	 *
	 * @return string Parsed mustache template string output.
	 */
	private function generate_default_conf( $site_type, $cache_type, $server_name ) {

		$default_conf_data['site_type']             = $site_type;
		$default_conf_data['site_url']              = $this->site_data['site_url'];
		$default_conf_data['server_name']           = $server_name;
		$default_conf_data['include_php_conf']      = ! $cache_type;
		$default_conf_data['include_wpsubdir_conf'] = $site_type === 'subdir';
		$default_conf_data['include_redis_conf']    = $cache_type;
		$default_conf_data['cache_host']            = $this->site_data['cache_host'];
		$default_conf_data['document_root']         = $this->site_data['site_container_fs_path'];

		return \EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', $default_conf_data );
	}


	/**
	 * Verify if the passed database credentials are working or not.
	 *
	 * @throws \Exception
	 */
	private function maybe_verify_remote_db_connection() {

		if ( in_array( $this->site_data['db_host'], [ 'db', GLOBAL_DB ], true ) ) {
			return;
		}
		$db_host        = $this->site_data['db_host'];
		$img_versions   = \EE\Utils\get_image_versions();
		$container_name = \EE\Utils\random_password();
		$network        = ( GLOBAL_DB === $this->site_data['db_host'] ) ? "--network='" . GLOBAL_FRONTEND_NETWORK . "'" : '';

		$run_temp_container = sprintf(
			'docker run --name %s %s -e MYSQL_ROOT_PASSWORD=%s -d --restart always easyengine/mariadb:%s',
			$container_name,
			$network,
			\EE\Utils\random_password(),
			$img_versions['easyengine/mariadb']
		);
		if ( ! \EE::exec( $run_temp_container ) ) {
			\EE::exec( "docker rm -f $container_name" );
			throw new \Exception( 'There was a problem creating container to test mysql connection. Please check the logs' );
		}

		// Docker needs special handling if we want to connect to host machine.
		// The since we're inside the container and we want to access host machine,
		// we would need to replace localhost with default gateway.
		if ( '127.0.0.1' === $db_host || 'localhost' === $db_host ) {
			$launch = \EE::launch( sprintf( "docker exec %s bash -c \"ip route show default | cut -d' ' -f3\"", $container_name ) );

			if ( ! $launch->return_code ) {
				$db_host = trim( $launch->stdout, "\n" );
			} else {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( 'There was a problem in connecting to the database. Please check the logs' );
			}
		}

		\EE::log( 'Verifying connection to remote database' );

		$check_db_connection = sprintf(
			"docker exec %s sh -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='EXIT'\"",
			$container_name,
			$db_host,
			$this->site_data['db_port'],
			$this->site_data['db_user'],
			$this->site_data['db_password']
		);
		if ( ! \EE::exec( $check_db_connection ) ) {
			\EE::exec( "docker rm -f $container_name" );
			throw new \Exception( 'Unable to connect to remote db' );
		}
		\EE::success( 'Connection to remote db verified' );

		$name            = str_replace( '_', '\_', $this->site_data['db_name'] );
		$check_db_exists = sprintf( "docker exec %s bash -c \"mysqlshow --user='%s' --password='%s' --host='%s' --port='%s' '%s'\"", $container_name, $this->site_data['db_user'], $this->site_data['db_password'], $db_host, $this->site_data['db_port'], $name );

		if ( ! \EE::exec( $check_db_exists ) ) {
			\EE::log( sprintf( 'Database `%s` does not exist. Attempting to create it.', $this->site_data['db_name'] ) );
			$create_db_command = sprintf(
				"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='CREATE DATABASE %s;'\"",
				$container_name,
				$db_host,
				$this->site_data['db_port'],
				$this->site_data['db_user'],
				$this->site_data['db_password'],
				$this->site_data['db_name']
			);

			if ( ! \EE::exec( $create_db_command ) ) {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( sprintf(
					'Could not create database `%s` on `%s:%s`. Please check if %s has rights to create database or manually create a database and pass with `--dbname` parameter.',
					$this->site_data['db_name'],
					$this->site_data['db_host'],
					$this->site_data['db_port'],
					$this->site_data['db_user']
				) );
			}
		} else {
			if ( $this->force ) {
				\EE::exec(
					sprintf(
						"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='DROP DATABASE %s;'\"",
						$container_name,
						$db_host,
						$this->site_data['db_port'],
						$this->site_data['db_user'],
						$this->site_data['db_password'],
						$this->site_data['db_name']
					)
				);
				\EE::exec(
					sprintf(
						"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='CREATE DATABASE %s;'\"",
						$container_name,
						$db_host,
						$this->site_data['db_port'],
						$this->site_data['db_user'],
						$this->site_data['db_password'],
						$this->site_data['db_name']
					)
				);
			}
			$check_tables = sprintf(
				"docker exec %s bash -c \"mysql --host='%s' --port='%s' --user='%s' --password='%s' --execute='USE %s; show tables;'\"",
				$container_name,
				$db_host,
				$this->site_data['db_port'],
				$this->site_data['db_user'],
				$this->site_data['db_password'],
				$this->site_data['db_name']
			);

			$launch = \EE::launch( $check_tables );
			if ( ! $launch->return_code ) {
				$tables = trim( $launch->stdout, "\n" );
				if ( ! empty( $tables ) ) {
					\EE::exec( "docker rm -f $container_name" );
					throw new \Exception( sprintf( 'Some database tables seem to exist in database %s. Please backup and reset the database or use `--force` in the site create command to reset it.', $this->site_data['db_name'] ) );
				}
			} else {
				\EE::exec( "docker rm -f $container_name" );
				throw new \Exception( 'There was a problem in connecting to the database. Please check the logs' );
			}
		}
		\EE::exec( "docker rm -f $container_name" );
	}

	/**
	 * Function to create the site.
	 */
	private function create_site( $assoc_args ) {

		$this->level                     = 1;
		try {
			if ( 'inherit' === $this->site_data['site_ssl'] ) {
				$this->check_parent_site_certs( $this->site_data['site_url'] );
			}

			\EE\Site\Utils\create_site_root( $this->site_data['site_fs_path'], $this->site_data['site_url'] );
			$this->level = 2;
			$this->maybe_verify_remote_db_connection();
			$this->configure_site_files();
			$this->level = 3;

			\EE\Site\Utils\configure_postfix( $this->site_data['site_url'], $this->site_data['site_fs_path'] );
			$this->wp_download_and_config( $assoc_args );

			if ( ! $this->skip_install ) {
				if ( ! $this->site_data['site_ssl'] || 'self' === $this->site_data['site_ssl'] ) {
					\EE\Site\Utils\create_etc_hosts_entry( $this->site_data['site_url'] );
				}
				if ( ! $this->skip_status_check ) {
					$this->level = 4;
					\EE\Site\Utils\site_status_check( $this->site_data['site_url'] );
				}
				$this->install_wp();

				if ( $this->is_vip ) {
					$this->setup_vip( $assoc_args );
				}

				// Clone repo content into wp-content directory
				if ( $this->is_git_repo ) {
					// Check if provided git repo is accessible.
					if ( ! $this->check_git_repo_access( $this->git_repo ) ) {
						EE::error( "Could not read from remote repository. Please make sure you have the correct access rights and the repository exists." );
					} else {
						EE::log( PHP_EOL . "Repo access check completed." . PHP_EOL );
						// Clone the repo content, defaults to htdocs directory.
						$this->complete_git_clone( $this->site_data['site_fs_path'], 'wp' );
					}
				}
			}

			if ( 'custom' === $this->site_data['site_ssl'] ) {
				$this->custom_site_ssl();
			}
			$this->www_ssl_wrapper( [ 'nginx' ] );
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}

		if ( ! empty( $this->cache_type ) ) {

			$this->enable_object_cache();
			$this->enable_page_cache();

			if ( 'on' === $this->site_data['proxy-cache'] ) {
				$this->update_proxy_cache( [], $assoc_args, true );
			}

			if ( $this->is_vip ) {
				EE::warning( 'Nginx-helper and wp-redis plugin is installed to enable cache. Please add it in your .gitignore to avoid it from git diff and commit' );
				EE::log( 'Note: Redis cache is setup for this site so it will use wp-redis object cache but not the memcache which is mentioned in VIP development doc from mu-plugin drop-ins.' );
			}
		}

		$wp_root_dir = $this->site_data['site_fs_path'] . '/app/htdocs';

		if ( $this->is_vip && file_exists( $wp_root_dir . '/mu-plugins' ) ) {
			// Enable VIP MU plugins.
			$this->fs->rename( $wp_root_dir . '/mu-plugins', $wp_root_dir . '/wp-content/mu-plugins' );
		}

		// Reset wp-content permission which may have been changed during git clone from host machine.
		EE::exec( "docker-compose exec --user=root php chown -R www-data: /var/www/htdocs/wp-content" );

		$this->create_site_db_entry();
		\EE::log( 'Site entry created.' );

		\EE::log( 'Creating cron entry' );
		$cron_interval = rand( 0, 9 );
		\EE::runcommand( 'cron create ' . $this->site_data['site_url'] . " --user=www-data --command='wp cron event run --due-now' --schedule='$cron_interval/10 * * * *'" );

		$this->info( [ $this->site_data['site_url'] ], [] );
	}

	/**
	 * Download and configure WordPress according to the user passed parameters.
	 *
	 * @param array $assoc_args Associative arguments passed during site creation.
	 *
	 * @throws \EE\ExitException
	 * @throws \Exception
	 */
	private function wp_download_and_config( $assoc_args ) {

		$core_download_args = [
			'version',
			'skip-content',
		];

		$config_args = [
			'dbprefix',
			'dbcharset',
			'dbcollate',
			'skip-check',
		];

		$core_download_arguments = '';
		if ( ! empty( $assoc_args ) ) {
			foreach ( $assoc_args as $key => $value ) {
				$core_download_arguments .= in_array( $key, $core_download_args, true ) ? ' --' . $key . '=' . $value : '';
			}
		}

		$config_arguments = '';
		if ( ! empty( $assoc_args ) ) {
			foreach ( $assoc_args as $key => $value ) {
				$config_arguments .= in_array( $key, $config_args, true ) ? ' --' . $key . '=' . $value : '';
			}
		}

		\EE::log( 'Downloading and configuring WordPress.' );

		// Get site src path from container fs path.
		$public_dir_path = str_replace( '/var/www/htdocs/', '', trailingslashit( $this->site_data['site_container_fs_path'] ) );
		if ( ! empty( $public_dir_path ) ) {

			$wp_cli_data = EE\Utils\mustache_render( SITE_WP_TEMPLATE_ROOT . '/wp-cli.yml.mustache', [ 'wp_path' => $public_dir_path ] );
			$this->fs->dumpFile( $this->site_data['site_fs_path'] . '/app/htdocs/wp-cli.yml', $wp_cli_data );

			EE::exec( sprintf( 'docker-compose exec --user=root php mkdir -p %s', $public_dir_path ) );
		}

		$chown_command = "docker-compose exec --user=root php chown -R www-data: /var/www/";
		\EE::exec( $chown_command );

		$wp_download_path      = $this->site_data['site_container_fs_path'];
		$core_download_command = "docker-compose exec --user='www-data' php wp core download --path=$wp_download_path --locale='$this->locale' $core_download_arguments";

		if ( ! \EE::exec( $core_download_command ) ) {
			\EE::error( 'Unable to download wp core.', false );
		}

		if ( 'db' === $this->site_data['db_host'] ) {
			$mysql_unhealthy = true;
			$health_chk      = sprintf( "docker-compose exec --user='www-data' php mysql --user='root' --password='%s' --host='db' -e exit", $this->site_data['db_root_password'] );
			$count           = 0;
			while ( $mysql_unhealthy ) {
				$mysql_unhealthy = ! \EE::exec( $health_chk );
				if ( $count ++ > 30 ) {
					break;
				}
				sleep( 1 );
			}
		}

		// Added wp-config.php debug constants referred from https://codex.wordpress.org/Debugging_in_WordPress.
		$extra_php = "if ( isset( \$_SERVER[\"HTTP_X_FORWARDED_PROTO\"] ) && \$_SERVER[\"HTTP_X_FORWARDED_PROTO\"] == \"https\" ) {\n	\$_SERVER[\"HTTPS\"] = \"on\";\n}\n\n// Enable WP_DEBUG mode.\ndefine( \"WP_DEBUG\", false );\n\n// Enable Debug logging to the /wp-content/debug.log file\ndefine( \"WP_DEBUG_LOG\", false );\n\n// Disable display of errors and warnings.\ndefine( \"WP_DEBUG_DISPLAY\", false );\n@ini_set( \"display_errors\", 0 );\n\n// Use dev versions of core JS and CSS files (only needed if you are modifying these core files)\ndefine( \"SCRIPT_DEBUG\", false );";

		if ( 'wp' !== $this->site_data['app_sub_type'] ) {
			$extra_php .= "\n\n// Disable cookie domain.\ndefine( \"COOKIE_DOMAIN\", false );";
		}

		if ( $this->is_vip ) {
			$extra_php .= "\n\nif ( file_exists( __DIR__ . \"/htdocs/wp-content/vip-config/vip-config.php\" ) ) {\n		require_once( __DIR__ . \"/htdocs/wp-content/vip-config/vip-config.php\" ); \n}";
		}

		$db_host                  = isset( $this->site_data['db_port'] ) ? $this->site_data['db_host'] . ':' . $this->site_data['db_port'] : $this->site_data['db_host'];
		$wp_config_create_command = sprintf( 'docker-compose exec --user=\'www-data\' php wp config create --dbuser=\'%s\' --dbname=\'%s\' --dbpass=\'%s\' --dbhost=\'%s\' %s --extra-php=\'%s\'', $this->site_data['db_user'], $this->site_data['db_name'], $this->site_data['db_password'], $db_host, $config_arguments, $extra_php );

		try {
			if ( ! \EE::exec( $wp_config_create_command ) ) {
				throw new \Exception( sprintf( 'Couldn\'t connect to %s:%s or there was issue in `wp config create`. Please check logs.', $this->site_data['db_host'], $this->site_data['db_port'] ) );
			}

			$default_wp_config_path = sprintf( '%s/wp-config.php', $this->site_data['site_container_fs_path'] );
			$level_above_path       = preg_replace( '/[^\/]+$/', '', $this->site_data['site_container_fs_path'] );
			$new_wp_config_path     = sprintf( '%swp-config.php', $level_above_path );

			$move_wp_config_command = sprintf( 'docker-compose exec php mv %1$s %2$s', $default_wp_config_path, $new_wp_config_path );
			if ( ! EE::exec( $move_wp_config_command ) ) {
				throw new \Exception( sprintf( 'Couldn\'t move wp-config.php from %1$s to %2$s', $default_wp_config_path, $new_wp_config_path ) );
			}
			EE::log( sprintf( 'Moved %1$s to %2$s successfully', $default_wp_config_path, $new_wp_config_path ) );

		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}

	}

	/**
	 * Install WordPress with given credentials.
	 */
	private function install_wp() {

		\EE::log( "Installing WordPress site." );
		chdir( $this->site_data['site_fs_path'] );

		$wp_install_command   = 'install';
		$maybe_multisite_type = '';

		if ( 'subdom' === $this->site_data['app_sub_type'] || 'subdir' === $this->site_data['app_sub_type'] ) {
			$wp_install_command   = 'multisite-install';
			$maybe_multisite_type = $this->site_data['app_sub_type'] === 'subdom' ? '--subdomains' : '';
		}

		$prefix          = ( $this->site_data['site_ssl'] ) ? 'https://' : 'http://';
		$install_command = sprintf( 'docker-compose exec --user=\'www-data\' php wp core %s --url=\'%s%s\' --title=\'%s\' --admin_user=\'%s\'', $wp_install_command, $prefix, $this->site_data['site_url'], $this->site_data['app_admin_url'], $this->site_data['app_admin_username'] );
		$install_command .= $this->site_data['app_admin_password'] ? sprintf( ' --admin_password=\'%s\'', $this->site_data['app_admin_password'] ) : '';
		$install_command .= sprintf( ' --admin_email=\'%s\' %s', $this->site_data['app_admin_email'], $maybe_multisite_type );

		$core_install = \EE::exec( $install_command, false, false, [
			$this->site_data['app_admin_username'],
			$this->site_data['app_admin_email'],
			$this->site_data['app_admin_password'],
		] );

		EE::exec( 'docker-compose exec php wp rewrite structure "/%year%/%monthnum%/%day%/%postname%/" --hard' );

		if ( ! $core_install ) {
			throw new \Exception( 'WordPress install failed. Please check logs.' );
		}

		\EE::success( $prefix . $this->site_data['site_url'] . ' has been created successfully!' );
	}

	/**
	 * Check access of wp-content repo provided from --vip flag/arg.
	 *
	 * @param string $wp_content_repo git repo url or user/repo-name.
	 *
	 * @return void
	 */
	private function check_repo_access( $wp_content_repo ) {

		$this->is_vip                    = true;
		$this->site_meta['vip_repo_url'] = $wp_content_repo;

		$git_check = \EE::exec( 'command -v git' );

		if ( ! $git_check ) {
			EE::error( 'git command not found. Please install git to setup vip github repo.' );
		}

		$check_repo_access = false;

		if ( $this->vip_go_skeleton !== $wp_content_repo ) {
			EE::warning( 'Your repo is being cloned at wp-content directory make sure the repo has wp-content data.' );

			EE::log( "Checking VIP repo access..." );

			$is_valid_git_url = false;

			if ( 0 === strpos( $wp_content_repo, 'git@github.com' ) ) {
				$is_valid_git_url = true;
			}

			if ( 0 === strpos( $wp_content_repo, 'https://github.com' ) ) {
				$is_valid_git_url = true;
			}

			if ( empty( $is_valid_git_url ) ) {
				$ssh_git_url = 'git@github.com:' . $wp_content_repo . '.git';

				$is_valid_git_url = EE::exec( 'git ls-remote --exit-code -h ' . $ssh_git_url );

				if ( $is_valid_git_url ) {
					$wp_content_repo                 = $ssh_git_url;
					$this->site_meta['vip_repo_url'] = $ssh_git_url;
					$check_repo_access               = true;
				} else {
					$https_git_url    = 'https://github.com/' . $wp_content_repo . '.git';
					$is_valid_git_url = EE::exec( 'git ls-remote --exit-code -h ' . $https_git_url );

					if ( $is_valid_git_url ) {
						$wp_content_repo                 = $https_git_url;
						$this->site_meta['vip_repo_url'] = $https_git_url;
						$check_repo_access               = true;
					}
				}
			}
		}

		if ( empty( $check_repo_access ) ) {
			$check_repo_access = \EE::exec( 'git ls-remote --exit-code -h ' . $wp_content_repo );

			if ( ! $check_repo_access ) {
				EE::error( "Could not read from remote repository. Please make sure you have the correct access rights and the repository exists." );
			}
		}

		EE::log( "Repo access check completed." );
	}

	/**
	 * Setup VIP Repo and mu plugins into wp-content.
	 */
	private function setup_vip( $assoc_args ) {

		\EE::log( "Setting up VIP Go environment. This may take time based on your repo size, please wait for a while..." );

		$site_wp_root_dir = $this->site_data['site_fs_path'] . '/app/htdocs';

		chdir( $site_wp_root_dir );

		if ( file_exists( './wp-content' ) ) {
			$this->fs->rename( './wp-content', './wp-content-bkp' );
		}

		$repo_clone_cmd = 'git clone ' . $this->site_meta['vip_repo_url'] . ' wp-content';

		$vip_repo_clone = \EE::exec( $repo_clone_cmd, true, true );

		if ( ! $vip_repo_clone ) {
			\EE::warning( 'Git clone failed. Please check your repo access.' );

			$this->fs->rename( './wp-content-bkp', './wp-content' );
		}

		if ( file_exists( './wp-content-bkp' ) ) {
			$this->fs->remove( './wp-content-bkp' );
		}

		if ( ! empty( $this->cache_type ) ) {
			// Add uploads dir if not exists - this will require during cache operation from nginx-helper.
			$this->fs->mkdir( './wp-content/uploads' );
		}

		$skip_vip_mu_plugins = false;

		if ( file_exists( './wp-content/mu-plugins' ) ) {
			EE::warning( 'You already have mu-plugins directory from your remote repo. This should be used from VIP mu plugins. Please move your all current mu-plugins to client-mu-plugins.' );
			$move_mu_plugins = EE::confirm( 'Continue to move your mu-plugins to client-mu-plugins?', $assoc_args, false );

			if ( $move_mu_plugins ) {
				if ( file_exists( './wp-content/client-mu-plugins' ) ) {
					EE::warning( 'It seems you already have client-mu-plugins directory. Please move your mu-plugins to proper place to and manually install VIP mu-plugins using below command.' );
					EE::log( 'git clone --depth=1 ' . $this->vip_go_mu_plugins . ' mu-plugins' );
					$skip_vip_mu_plugins = true;
				} else {
					$this->fs->rename( './wp-content/mu-plugins', './wp-content/client-mu-plugins' );
					EE::log( 'Moved all mu-plugins to client-mu-plugins successfully.' );
				}
			} else {
				$skip_vip_mu_plugins = true;
			}
		}

		if ( $skip_vip_mu_plugins ) {
			EE::log( 'VIP mu-plugins installation is skipped.' );
		} else {
			// Clone at wp root dir and move it to wp-content dir later.
			// Spacial case for --cache where file operations are happening and vip mu plugins are preventing this.
			$mu_plugins_clone_cmd = 'git clone --depth=1 ' . $this->vip_go_mu_plugins . ' mu-plugins';

			$mu_plugins_clone = \EE::exec( $mu_plugins_clone_cmd );

			if ( ! $mu_plugins_clone ) {
				\EE::warning( 'VIP mu-plugin git clone failed. Please check if  ' . $this->vip_go_mu_plugins . ' repo is accessible to you.' );

				$this->fs->remove( './mu-plugins' );
			}
		}

		// Get back to root dir.
		chdir( $this->site_data['site_fs_path'] );

		// Reset wp-content permission which may have been changed during git clone from host machine. Making it `/var/www/htdocs/` so that it accomodates the changes of `--public-dir` input if any.
		EE::exec( "docker-compose exec --user=root php chown -R www-data: /var/www/htdocs/" );

		\EE::log( "VIP Go environment setup completed." );
	}

	/**
	 * Function to save the site configuration entry into database.
	 */
	private function create_site_db_entry() {
		$ssl = null;

		$data = [
			'site_url'               => $this->site_data['site_url'],
			'site_type'              => $this->site_data['site_type'],
			'app_admin_url'          => $this->site_data['app_admin_url'],
			'app_admin_email'        => $this->site_data['app_admin_email'],
			'app_mail'               => 'postfix',
			'app_sub_type'           => $this->site_data['app_sub_type'],
			'alias_domains'          => $this->site_data['alias_domains'],
			'cache_nginx_browser'    => (int) $this->cache_type,
			'cache_nginx_fullpage'   => (int) $this->cache_type,
			'cache_mysql_query'      => (int) $this->cache_type,
			'cache_app_object'       => (int) $this->cache_type,
			'cache_host'             => $this->site_data['cache_host'],
			'proxy_cache'            => $this->site_data['proxy-cache'],
			'site_fs_path'           => $this->site_data['site_fs_path'],
			'db_name'                => $this->site_data['db_name'],
			'db_user'                => $this->site_data['db_user'],
			'db_host'                => $this->site_data['db_host'],
			'db_port'                => isset( $this->site_data['db_port'] ) ? $this->site_data['db_port'] : '',
			'db_password'            => $this->site_data['db_password'],
			'db_root_password'       => $this->site_data['db_root_password'],
			'site_ssl'               => $this->site_data['site_ssl'],
			'site_ssl_wildcard'      => 'subdom' === $this->site_data['app_sub_type'] || $this->site_data['site_ssl_wildcard'] ? 1 : 0,
			'php_version'            => $this->site_data['php_version'],
			'created_on'             => date( 'Y-m-d H:i:s', time() ),
			'site_container_fs_path' => rtrim( $this->site_data['site_container_fs_path'], '/' ),
		];

		if ( ! $this->skip_install ) {
			$data['app_admin_username'] = $this->site_data['app_admin_username'];
			$data['app_admin_password'] = $this->site_data['app_admin_password'];
		}

		try {
			$site_id = Site::create( $data );

			if ( ! $site_id ) {
				throw new \Exception( 'Error creating site entry in database.' );
			}

			$vip_repo_url = $this->site_meta['vip_repo_url'] ?? '';

			if ( ! empty( $vip_repo_url ) ) {
				Site_Meta::set( $site_id, 'vip_repo_url', $vip_repo_url );
				Site_Meta::set( $site_id, 'is_vip', true );
			}
		} catch ( \Exception $e ) {
			$this->catch_clean( $e );
		}
	}

	/**
	 * Funciton to update ssl of a site.
	 */
	protected function update_ssl( $assoc_args ) {

		parent::update_ssl( $assoc_args );
		chdir( $this->site_data['site_fs_path'] );

		EE::log( 'Running search-replace.' );
		EE::log( 'Taking database backup before search-replace.' );
		EE::exec( sprintf( 'docker-compose exec php wp db export %s.db', $this->site_data['site_url'] ) );

		$db_file         = $this->site_data['site_fs_path'] . '/app/htdocs/' . $this->site_data['site_url'] . '.db';
		$backup_location = EE_BACKUP_DIR . '/' . $this->site_data['site_url'] . '/' . $this->site_data['site_url'] . '.db';
		$this->fs->mkdir( dirname( $backup_location ) );

		$backup_success = false;
		if ( $this->fs->exists( $db_file ) ) {
			$this->fs->remove( $backup_location );
			$this->fs->rename( $db_file, $backup_location );
			$backup_success = true;
		}

		$extra_flags = '--precise';
		$extra_flags .= ( 'wp' === $this->site_data['app_sub_type'] ) ? '' : ' --network';
		EE::exec( sprintf( 'docker-compose exec php wp search-replace http://%1$s https://%1$s %2$s', $this->site_data['site_url'], $extra_flags ), true, true );
		EE::success( 'Successfully completed search-replace.' );

		if ( $backup_success ) {
			EE::log( "In case something is not working as intended. You can restore your DB from backup file generated before search-replace located at:\n `$backup_location`\nand proceed with search-replace according to your needs." );
		}
	}

	/**
	 * Restarts containers associated with site.
	 * When no service(--nginx etc.) is specified, all site containers will be restarted.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Restart all containers of site.
	 *
	 * [--nginx]
	 * : Restart nginx container of site.
	 *
	 * [--php]
	 * : Restart php container of site.
	 *
	 * [--db]
	 * : Restart db container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart all containers of site
	 *     $ ee site restart example.com
	 *
	 *     # Restart single container of site
	 *     $ ee site restart example.com --nginx
	 *
	 */
	public function restart( $args, $assoc_args, $whitelisted_containers = [] ) {

		$args                   = auto_site_name( $args, 'site', __FUNCTION__ );
		$this->site_data        = get_site_info( $args, false );
		$whitelisted_containers = [ 'nginx', 'php' ];

		if ( 'db' === $this->site_data['db_host'] ) {
			$whitelisted_containers[] = 'db';
		}

		parent::restart( $args, $assoc_args, $whitelisted_containers );
	}

	/**
	 * Reload services in containers without restarting container(s) associated with site.
	 * When no service(--nginx etc.) is specified, all services will be reloaded.
	 *
	 * [<site-name>]
	 * : Name of the site.
	 *
	 * [--all]
	 * : Reload all services of site(which are supported).
	 *
	 * [--nginx]
	 * : Reload nginx service in container.
	 *
	 * [--php]
	 * : Reload php container of site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reload all containers of site
	 *     $ ee site reload example.com
	 *
	 *     # Reload single containers of site
	 *     $ ee site reload example.com --nginx
	 *
	 */
	public function reload( $args, $assoc_args, $whitelisted_containers = [], $reload_commands = [] ) {
		$whitelisted_containers = [ 'nginx', 'php' ];
		$reload_commands['php'] = "php bash -c 'kill -USR2 1'";
		parent::reload( $args, $assoc_args, $whitelisted_containers, $reload_commands );
	}


	/**
	 * Share a site online using ngrok.
	 *
	 * ## OPTIONS
	 *
	 * <site-name>
	 * : Name of website.
	 *
	 * [--disable]
	 * : Take online link down.
	 *
	 * [--refresh]
	 * : Refresh site share if link has expired.
	 *
	 * [--token=<token>]
	 * : ngrok token.
	 *
	 * ## EXAMPLES
	 *
	 *     # Share a site online
	 *     $ ee site share example.com
	 *
	 *     # Refresh shareed link if expired
	 *     $ ee site share example.com --refresh
	 *
	 *     # Disable online link
	 *     $ ee site share example.com --disable
	 *
	 */
	public function share( $args, $assoc_args ) {
		parent::share( $args, $assoc_args );

		$disable = get_flag_value( $assoc_args, 'disable', false );
		$refresh = get_flag_value( $assoc_args, 'refresh', false );

		if ( $refresh ) {
			return;
		}

		EE::log( 'Running additional WordPress configurations.' );
		chdir( $this->site_data->site_fs_path );
		if ( $disable ) {
			EE::exec( 'docker-compose exec --user=\'www-data\' php wp plugin delete relative-url' );
			EE::exec( 'docker-compose exec --user=\'www-data\' php wp config delete WP_SITEURL' );
			EE::exec( 'docker-compose exec --user=\'www-data\' php wp config delete WP_HOME' );
		} else {
			EE::exec( 'docker-compose exec --user=\'www-data\' php wp plugin install relative-url --activate' );
			$set_url = 'http://\' . empty( \$_SERVER[\'HTTP_HOST\'] ) ? \'' . $this->site_data->site_url . '\'  : \$_SERVER[\'HTTP_HOST\']';
			EE::exec( 'docker-compose exec --user=\'www-data\' php wp config set --type=constant WP_SITEURL \'' . $set_url . '\' --raw' );
			EE::exec( 'docker-compose exec --user=\'www-data\' php wp config set --type=constant WP_HOME \'' . $set_url . '\' --raw' );
		}

		EE::success( 'WordPress configurations updated for publish.' );
	}

	/**
	 * Catch and clean exceptions.
	 *
	 * @param \Exception $e
	 */
	private function catch_clean( $e ) {
		\EE\Utils\delem_log( 'site cleanup start' );
		\EE::warning( $e->getMessage() );
		\EE::warning( 'Initiating clean-up.' );
		$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
			'db_host' => $this->site_data['db_host'],
			'db_user' => $this->site_data['db_user'],
			'db_name' => $this->site_data['db_name'],
		];
		$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );
		\EE\Utils\delem_log( 'site cleanup end' );
		exit;
	}

	/**
	 * Roll back on interrupt.
	 */
	protected function rollback() {
		\EE::warning( 'Exiting gracefully after rolling back. This may take some time.' );
		if ( $this->level > 0 ) {
			$db_data = ( empty( $this->site_data['db_host'] ) || 'db' === $this->site_data['db_host'] ) ? [] : [
				'db_host' => $this->site_data['db_host'],
				'db_user' => $this->site_data['db_user'],
				'db_name' => $this->site_data['db_name'],
			];
			$this->delete_site( $this->level, $this->site_data['site_url'], $this->site_data['site_fs_path'], $db_data );
		}
		\EE::success( 'Rollback complete. Exiting now.' );
		exit;
	}

}
