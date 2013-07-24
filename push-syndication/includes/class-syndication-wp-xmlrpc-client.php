<?php

include_once( ABSPATH . 'wp-includes/class-IXR.php' );
include_once( ABSPATH . 'wp-includes/class-wp-http-ixr-client.php' );
include_once( dirname(__FILE__) . '/interface-syndication-client.php' );
include_once( dirname( __FILE__ ) . '/push-syndicate-encryption.php' );

class Syndication_WP_XMLRPC_Client extends WP_HTTP_IXR_Client implements Syndication_Client {

	private $username;
	private $password;

	private $site_ID;

	function __construct( $site_ID ) {

		// @TODO check port, timeout etc
		$server = untrailingslashit( get_post_meta( $site_ID, 'syn_site_url', true ) );
		if ( false === strpos( $server, 'xmlrpc.php' ) )
			$server = esc_url_raw( trailingslashit( $server ) . 'xmlrpc.php' );
		else
			$server = esc_url_raw( $server );

		$this->username = get_post_meta( $site_ID, 'syn_site_username', true);
		$this->password = push_syndicate_decrypt( get_post_meta( $site_ID, 'syn_site_password', true) );
		$this->site_ID  = $site_ID;

		parent::__construct( $server );

		if ( true === apply_filters( 'syn_xmlrpc_push_send_thumbnail', true, $site_ID, $this ) ) {
			add_action( 'syn_xmlrpc_push_new_post_success', array( $this, 'post_push_send_thumbnail' ), 10, 6 );
			add_action( 'syn_xmlrpc_push_edit_post_success', array( $this, 'post_push_send_thumbnail' ), 10, 6 );
			// TODO: on delete post, delete thumbnail
		}
	}

	function post_push_send_thumbnail( $remote_post_id, $post_id ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		$syndicated_thumbnail_id = get_post_meta( $post_id, '_syn_push_syndicated_thumbnail', true );

		if ( ! $thumbnail_id ) {
			if ( $syndicated_thumbnail_id ) {
				$result = $this->query(
					'syndication.deleteThumbnail',
					'1',
					$this->username,
					$this->password,
					$remote_post_id
				);

				delete_post_meta( $post_id, '_syn_push_syndicated_thumbnail' );

			}
			return;
		}

		if ( $syndicated_thumbnail_id == $thumbnail_id )
			return;

		list( $thumbnail_url ) = wp_get_attachment_image_src( $thumbnail_id, 'thumbnail' );

		$result = $this->query(
			'syndication.addThumbnail',
			'1',
			$this->username,
			$this->password,
			$remote_post_id,
			$thumbnail_url
		);

		if ( $result ) {
			update_post_meta( $post_id, '_syn_push_syndicated_thumbnail', $thumbnail_id );
		}
	}

	public static function get_client_data() {
		return array( 'id' => 'WP_XMLRPC', 'modes' => array( 'push' ), 'name' => 'WordPress XMLRPC' );
	}
	
	public function new_post( $post_ID ) {

		$post = (array)get_post( $post_ID );

		// This filter can be used to exclude or alter posts during a content push
		$post = apply_filters( 'syn_xmlrpc_push_filter_new_post', $post, $post_ID );
		if ( false === $post )
			return true;
		
		// rearranging arguments
		$args = array();
		$args['post_title']	 = $post['post_title'];
		$args['post_content']   = $post['post_content'];
		$args['post_excerpt']   = $post['post_excerpt'];
		$args['post_status']	= $post['post_status'];
		$args['post_type']	  = $post['post_type'];
		$args['wp_password']	= $post['post_password'];
		$args['post_date_gmt']  = $this->convert_date_gmt( $post['post_date_gmt'], $post['post_date'] );

		$args['terms_names'] = $this->_get_post_terms( $post_ID );

		$args['custom_fields'] = $this->_get_custom_fields( $post_ID );

		$args = apply_filters( 'syn_xmlrpc_push_new_post_args', $args, $post );

		$result = $this->query(
			'wp.newPost',
			'1',
			$this->username,
			$this->password,
			$args
		);

		if ( ! $result ) {
			return new WP_Error( $this->getErrorCode(), $this->getErrorMessage() );
		}

		$remote_post_id = (int) $this->get_response();

		do_action( 'syn_xmlrpc_push_new_post_success', $remote_post_id, $post_ID );

		return $remote_post_id;

	}

	public function edit_post( $post_ID, $remote_post_id ) {

		$post = (array)get_post( $post_ID );

		// This filter can be used to exclude or alter posts during a content push
		$post = apply_filters( 'syn_xmlrpc_push_filter_edit_post', $post, $post_ID );
		if ( false === $post )
			return true;
		
		// rearranging arguments
		$args = array();
		$args['post_title']	 = $post['post_title'];
		$args['post_content']   = $post['post_content'];
		$args['post_excerpt']   = $post['post_excerpt'];
		$args['post_status']	= $post['post_status'];
		$args['post_type']	  = $post['post_type'];
		$args['wp_password']	= $post['post_password'];
		$args['post_date_gmt']  = $this->convert_date_gmt( $post['post_date_gmt'], $post['post_date'] );

		$args['terms_names'] = $this->_get_post_terms( $post_ID );

		$args['custom_fields'] = $this->_get_custom_fields( $post_ID );

		$args = apply_filters( 'syn_xmlrpc_push_edit_post_args', $args, $post );

		$result = $this->query(
			'wp.editPost',
			'1',
			$this->username,
			$this->password,
			$remote_post_id,
			$args
		);

		if ( ! $result ) {
			return new WP_Error( $this->getErrorCode(), $this->getErrorMessage() );
		}

		do_action( 'syn_xmlrpc_push_edit_post_success', $remote_post_id, $post_ID );

		return $remote_post_id;
	}

	public function delete_post( $remote_post_id ) {

		$result = $this->query(
				'wp.deletePost',
				'1',
				$this->username,
				$this->password,
				$remote_post_id
		);

		if ( ! $result ) {
			return new WP_Error( $this->getErrorCode(), $this->getErrorMessage() );
		}

		return true;
	}

	private function _get_custom_fields( $post_id ) {
		$post = get_post( $post_id );

		$custom_fields = array();
		$all_post_meta = get_post_custom( $post_id );

		$blacklisted_meta = $this->_get_meta_blacklist();
		foreach ( (array) $all_post_meta as $post_meta_key => $post_meta_values ) {

			if ( in_array( $post_meta_key, $blacklisted_meta ) || preg_match( '/^_?syn/i', $post_meta_key ) )
				continue;

			foreach ( $post_meta_values as $post_meta_value ) {
				$post_meta_value = maybe_unserialize( $post_meta_value ); // get_post_custom returns serialized data

				$custom_fields[] = array(
					'key' => $post_meta_key,
					'value' => $post_meta_value,
				);
			}
		}

		$custom_fields[] = array(
			'key' => '_masterpost_url',
			'value' => $post->guid,
		);
		return $custom_fields;
	}

	private function _get_meta_blacklist() {
		return apply_filters( 'syn_ignored_meta_fields', array( '_edit_last', '_edit_lock', /** TODO: add more **/ ) );
	}

	private function _get_post_terms( $post_id ) {
		$terms_names = array();

		$post = get_post( $post_id );

		if ( is_object_in_taxonomy( $post->post_type, 'category' ) )
			$terms_names['category'] = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'names' ) );

		if ( is_object_in_taxonomy( $post->post_type, 'post_tag' )  )
			$terms_names['post_tag'] = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );

		// TODO: custom taxonomy

		return $terms_names;
	}

	public function test_connection() {

		$result = $this->query(
			'wp.getPostTypes', // @TODO find a better suitable function
			'1',
			$this->username,
			$this->password
		);

		if( !$result ) {

			$error_code = absint($this->getErrorCode());

			switch( $error_code ) {
				case 32301:
					add_filter('redirect_post_location', create_function( '$location', 'return add_query_arg("message", 305, $location);' ) );
					break;
				case 401:
					add_filter('redirect_post_location', create_function( '$location', 'return add_query_arg("message", 302, $location);' ) );
					break;
				case 403:
					add_filter('redirect_post_location', create_function( '$location', 'return add_query_arg("message", 303, $location);' ) );
					break;
				case 405:
					add_filter('redirect_post_location', create_function( '$location', 'return add_query_arg("message", 304, $location);' ) );
					break;
				default:
					add_filter('redirect_post_location', create_function( '$location', 'return add_query_arg("message", 306, $location);' ) );
					break;
			}

			return false;

		}

		return true;

	}

	public function is_post_exists( $ext_ID ) {

		$result = $this->query(
			'wp.getPost',
			'1',
			$this->username,
			$this->password,
			$ext_ID
		);

		if( !$result )
			return false;

		$post = $this->getResponse();

		if( $ext_ID != $post['post_id'] )
			return false;

		return true;

	}

	protected function convert_date_gmt( $date_gmt, $date ) {
		if ( $date !== '0000-00-00 00:00:00' && $date_gmt === '0000-00-00 00:00:00' ) {
			return new IXR_Date( get_gmt_from_date( mysql2date( 'Y-m-d H:i:s', $date, false ), 'Ymd\TH:i:s' ) );
		}
		return $this->convert_date( $date_gmt );
	}

	protected function convert_date( $date ) {
		if ( $date === '0000-00-00 00:00:00' ) {
			return new IXR_Date( '00000000T00:00:00Z' );
		}
		return new IXR_Date( mysql2date( 'Ymd\TH:i:s', $date, false ) );
	}

	public static function display_settings( $site ) {

		$site_url = get_post_meta( $site->ID, 'syn_site_url', true);
		$site_username = get_post_meta( $site->ID, 'syn_site_username', true);
		$site_password = push_syndicate_decrypt( get_post_meta( $site->ID, 'syn_site_password', true) );

		?>

		<p>
			<label for=site_url><?php echo esc_html__( 'Enter a valid site URL', 'push-syndication' ); ?></label>
		</p>
		<p>
			<input type="text" class="widefat" name="site_url" id="site_url" size="100" value="<?php echo esc_html( $site_url ); ?>" />
		</p>
		<p>
			<label for="site_username"><?php echo esc_html__( 'Enter Username', 'push-syndication' ); ?></label>
		</p>
		<p>
			<input type="text" class="widefat" name="site_username" id="site_username" size="100" value="<?php echo esc_attr( $site_username ); ?>" />
		</p>
		<p>
			<label><?php echo esc_html__( 'Enter Password', 'push-syndication' ); ?></label>
		</p>
		<p>
			<input type="password" class="widefat" name="site_password" id="site_password" size="100"  autocomplete="off" value="<?php echo esc_attr( $site_password ); ?>" />
		</p>

		<?php

	}

	public static function save_settings( $site_ID ) {

		$_POST['site_url'] = str_replace( '/xmlrpc.php', '', $_POST['site_url'] );

		update_post_meta( $site_ID, 'syn_site_url', esc_url_raw( $_POST['site_url'] ) );
		update_post_meta( $site_ID, 'syn_site_username', sanitize_text_field( $_POST['site_username'] ) );
		update_post_meta( $site_ID, 'syn_site_password', push_syndicate_encrypt( sanitize_text_field( $_POST['site_password'] ) ) );

		if( !filter_var( $_POST['site_url'], FILTER_VALIDATE_URL ) ) {
			add_filter('redirect_post_location', create_function( '$location', 'return add_query_arg("message", 301, $location);' ) );
			return false;
		}

		return true;

	}

	public function get_post( $ext_ID )
	{
		// TODO: Implement get_post() method.
	}

	public function get_posts( $args = array() )
	{
		// TODO: Implement get_posts() method.
	}

}

class Syndication_WP_XMLRPC_Client_Extensions {

	public static function init() {
		add_filter( 'xmlrpc_methods' , array( __CLASS__, 'push_syndicate_methods' ) );
	}

	public static function push_syndicate_methods( $methods ) {
        $methods['syndication.addThumbnail']    = array( __CLASS__, 'xmlrpc_add_thumbnail' );
        $methods['syndication.deleteThumbnail']    = array( __CLASS__, 'xmlrpc_delete_thumbnail' );
		return $methods;
	}

	public static function xmlrpc_add_thumbnail( $args ) {
		global $wp_xmlrpc_server, $wpdb;

		$wp_xmlrpc_server->escape( $args );

		$blog_id	    = (int) $args[0];
		$username	    = $args[1];
		$password	    = $args[2];
		$post_ID            = (int)$args[3];
		$thumbnail_url     = esc_url_raw( $args[4] );

		if ( ! $post_ID )
			return new IXR_Error( 500, __( 'Please specify a valid post_ID.', 'syndication' ) );

		$thumbnail_raw = wp_remote_retrieve_body( wp_remote_get( $thumbnail_url ) );
		if ( ! $thumbnail_raw )
			return new IXR_Error( 500, __( 'Sorry, the image URL provided was incorrect.', 'syndication' ) );

		$thumbnail_filename = basename( $thumbnail_url );
		$thumbnail_type = wp_check_filetype( $thumbnail_filename );

		$args = array(
			$blog_id,
			$username,
			$password,
			array(
				'name'  => $thumbnail_filename,
				'type'  => $thumbnail_type['type'],
				'bits'  => $thumbnail_raw,
				'overwrite' => false,
			),
		);

		// Note: Leting mw_newMediaObject handle our auth and cap checks
		$image = $wp_xmlrpc_server->mw_newMediaObject( $args );

		if ( ! is_array( $image ) || empty( $image['url'] ) )
			return $image;

		$thumbnail_id = (int) $image['id'];
		if( empty( $thumbnail_id ) )
			return new IXR_Error( 500, __( 'Sorry, looks like the image upload failed.', 'syndication' ) );

		$thumbnail_set = set_post_thumbnail( $post_ID, $thumbnail_id );
		if ( ! $thumbnail_set )
			return new IXR_Error( 403, __( 'Could not attach post thumbnail.' ) );

		return $thumbnail_id;
	}

	public static function xmlrpc_delete_thumbnail( $args ) {

		global $wp_xmlrpc_server;
		$wp_xmlrpc_server->escape( $args );

		$blog_id	    = (int) $args[0];
		$username	    = $args[1];
		$password	    = $args[2];
		$post_ID            = (int)$args[3];

		if ( !$user = $wp_xmlrpc_server->login( $username, $password ) )
			return $wp_xmlrpc_server->error;

		if ( ! current_user_can( 'edit_post', $post_ID ) )
			return new IXR_Error( 401, __( 'Sorry, you are not allowed to post on this site.' ) );

		$result = delete_post_thumbnail( $post_ID );
		if ( ! $result )
			return new IXR_Error( 403, __( 'Could not remove post thumbnail.' ) );

		return true;

	}

}

Syndication_WP_XMLRPC_Client_Extensions::init();
