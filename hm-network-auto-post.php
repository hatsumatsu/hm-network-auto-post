<?php

/*
Plugin Name: HM Network Auto Post
Version: 0.1
Description: Activate on default language...
Plugin URI:
Author: Martin Wecke, HATSUMATSU
Author URI: http://hatsumatsu.de/
*/


class HMNetworkAutoPost {
	protected $mpl_api_cache;
	protected $settings;

	public function __construct() {
		// i11n
		load_plugin_textdomain( 'hm-network-auto-post' );		

		// cache MPL API
		add_action( 'inpsyde_mlp_loaded', array( $this, 'cache_mlp_api' ) );

		// load settings
		add_action( 'after_setup_theme', array( $this, 'load_settings' ) );

		// attach publish action 
		// use save_post to include ACF fields which fire late
		add_action( 'save_post', array( $this, 'copy_post' ), 100, 2 );			

		// init meta box
		add_action( 'load-post.php', array( $this, 'init_metabox' ) );
		add_action( 'load-post-new.php', array( $this, 'init_metabox' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_css' ) );

		// default settings
		$this->settings = array(
			// 'post' => array(
			// 	'post_status' 		=> 'publish',
			// 	'post_thumbnail' 	=> true,
			// 	'meta' 				=> array(
			// 		'custom'
			// 	)
			// )
		);
	}


	/**
	 * Load settings from filter 'hmnap/settings'
	 */
	public function load_settings() {
		$this->settings = apply_filters( 'hmnap/settings', $this->settings );			
	}


	/**
	 * Cache MultillingualPress API
	 */
	public function cache_mlp_api( $data ) {
	    $this->write_log( 'inpsyde_mlp_loaded' );

	    $this->mpl_api_cache = $data;	
	}


	/**
	 * Copy posts 
	 * @param int $post_id source post ID
	 * @param WP_Post $post source post
	 * @param bool $update Whether this is an existing post being updated or not.
	 */
	public function copy_post( $post_id, $post ) {
		$this->write_log( 'save_post' );
		$this->write_log( $post_id );
		$this->write_log( $post->ID );
		$this->write_log( $post->post_status );
		$this->write_log( $post->post_title );
		$this->write_log( $post->post_content );

		// return if post is post revisions
		if( wp_is_post_revision( $post_id ) ) {
			$this->write_log( 'ignore revision' );
			return;
		}

		// return if post is trash or auto draft
		if( $post->post_status == 'trash' || $post->post_status == 'draft' || $post->post_status == 'auto-draft' ) {
			$this->write_log( 'ignore auto draft or trash' );
			return;
		}

		// if post type is okay
		if( array_key_exists( $post->post_type, $this->settings ) ) {
			$this->write_log( 'post type is okay' );		

			if( !get_post_meta( $post_id, '_network-auto-post--synced', true ) ) {
				$this->write_log( 'post is not yet synced' );		

				$post_status = ( $this->settings[$post->post_type]['post_status'] ) ? $this->settings[$post->post_type]['post_status'] : 'draft';

				$post_data = array(
					'ID' 				=> 0,
					'post_title' 		=> $post->post_title,
					'post_content' 		=> $post->post_content,
					'post_date' 		=> $post->post_date,
					'post_modified' 	=> $post->post_modified,
					'post_modified_gmt' => $post->post_modified_gmt,
					'post_status' 		=> $post_status,
					'post_author' 		=> $post->post_author,
					'post_excerpt'  	=> $post->post_excerpt,
					'post_type' 		=> $post->post_type,
					'post_name' 		=> $post->post_name
				);

				// add meta data
				// flag copy as synced
				$meta = array(
					'_network-auto-post--synced' => 1
				);

				if( $this->settings[$post->post_type]['meta'] ) {
					foreach( $this->settings[$post->post_type]['meta'] as $key ) {
						$value = get_post_meta( $post_id, $key, true );
						if( $value ) {
							$meta[$key] = $value;
						}
					}
				}

				$post_data['meta_input'] = $meta;

				// flag source as synced
				update_post_meta( $post_id, '_network-auto-post--synced', 1 );

				$this->write_log( $post_data );		

			    $sites = wp_get_sites();
				$source_site_id = get_current_blog_id();

			    // each site... 
			    foreach( $sites as $site ) {
			        // ... that is not the source site
			        if( $site['blog_id'] != $source_site_id ) {
			        	$this->write_log( 'copy to blog id: ' . $site['blog_id'] );		


			            // if MultilingualPress is present
			            if( function_exists( 'mlp_get_linked_elements' ) ) {
			            	// get existing relations
			                $relations = mlp_get_linked_elements( $post_id, '', $source_site_id );
			                $this->write_log( $relations );

			                // if there are no related posts
			                if( !array_key_exists( $site['blog_id'], $relations ) ) {
			        			$this->write_log( 'there is no connectio yet: ' . $site['blog_id'] );		

			        			// switch to target site
			                	switch_to_blog( $site['blog_id'] );
			                	// create post
								$copy_id = wp_insert_post( $post_data );
								// switch back to source site
								restore_current_blog();

								// try to set MultilingualPress relation
			                    if( $this->mpl_api_cache ) {            
			                        $this->write_log( 'API cache found' );

			                        $relations = $this->mpl_api_cache->get( 'content_relations' );

			                        $relations->set_relation(
			                            $source_site_id,
			                            $site['blog_id'],
			                            $post_id,
			                            $copy_id
			                        );
			                    }		

			                    // call custom action 
			                    do_action( 
			                    	'hmnap/create_post',
		                            $source_site_id,
		                            $site['blog_id'],
		                            $post_id,
		                            $copy_id
		                        );		

			                    /**
			                     * set thumbnail image
			                     */
		                        // if source post has thumbnail
		                        if( $source_post_thumbnail_id = get_post_thumbnail_id( $post_id ) ) {
		                        	$this->write_log( 'Thumbnail found' );

		                        	// check for relations of thumbnail
		                        	$thumbnail_relations = mlp_get_linked_elements( $source_post_thumbnail_id, '', $source_site_id );
		                        	$this->write_log( $thumbnail_relations );

									// thumbnail relations exists
									if( array_key_exists( $site['blog_id'], $thumbnail_relations ) ) {
			                        	$this->write_log( 'Thumbnail relation found: ' . $thumbnail_relations[$site['blog_id']] );
		
					        			// switch to target site
					                	switch_to_blog( $site['blog_id'] );			                        	
										// set thumbnail on target site
			                        	update_post_meta( $copy_id, '_thumbnail_id', $thumbnail_relations[$site['blog_id']] );
										// switch back to source site
										restore_current_blog();			                        
			                        }

		                        }

		                        /**
		                         * TODO 
		                         * Find all related uploads of the source post's uploads
		                         * and set their post parent to the created post
		                         */
			                }
			            }
			        }
			    }
			}
		}
	}


    /**
     * Meta box initialization.
     */
    public function init_metabox() {
        add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
    }		


    /**
     * Adds the meta box.
     */
    public function add_metabox() {
    	// if MultilingualPress is present
    	if( function_exists( 'mlp_get_linked_elements' ) ) {
	    	$screens = array();
	    	foreach( $this->settings as $post_type => $settings ) {
	    		$screens[] = $post_type;
	    	}

	        add_meta_box(
	            'hm-network-auto-post',
	            __( 'Translations', 'hm-network-auto-post' ),
	            array( $this, 'render_metabox' ),
	            $screens,
	            'advanced',
	            'default'
	        );
	    }
    }


    /**
     * Renders the meta box.
     */
    public function render_metabox( $post ) {

		if( function_exists( 'mlp_get_linked_elements' ) ) {
		    $sites = wp_get_sites();
			$source_site_id = get_current_blog_id();

			// get existing relations
			$relations = mlp_get_linked_elements( $post->ID, '', $source_site_id );
			if( $relations ) {

				$language_titles = ( function_exists( 'mlp_get_available_languages_titles' ) ) ? mlp_get_available_languages_titles() : array();

				foreach( $relations as $site_id => $relation ) {
					if( $site_id !== $source_site_id ) {
						switch_to_blog( $site_id );

						echo '<div class="hmnap-language">';
						echo '<h3>';
						echo '<a href="' . get_edit_post_link( $relation ) . '">';
						echo get_the_title( $relation );
						echo '</a>';
						echo '</h3>';
						echo '<h4 class="info">';
						// echo get_bloginfo( 'name' );
						if( array_key_exists( $site_id, $language_titles ) ) {
							echo $language_titles[ $site_id ];
						}
						echo '</h4>';
						// echo '<a href="' . get_edit_post_link( $relation ) . '" class="button">';
						// echo __( 'Edit', 'hm-network-auto-post' );
						// echo '</a>';
						echo '</div>';
						restore_current_blog();
					}
				}
			}
		}
    }    


	/**
	 * Register admin CSS
	 *
	 */
	public function admin_css() {
	    wp_register_style( 'hmnap-admin-style', WP_PLUGIN_URL . '/hm-network-auto-post/css/hm-network-auto-post-admin.css' );
	    wp_enqueue_style( 'hmnap-admin-style' );
	} 


	/**
	 * Write log if WP_DEBUG is active
	 * @param  string|array $log 
	 */
	public function write_log( $log )  {
	    // if( true === WP_DEBUG ) {
	        if( is_array( $log ) || is_object( $log ) ) {
	            error_log( print_r( 'hmnap: ' . $log . "\n", true ), 3, trailingslashit( ABSPATH ) . 'wp-content/debuglog.log' );
	        } else {
	            error_log( 'hmnap: ' . $log . "\n", 3, trailingslashit( ABSPATH ) . 'wp-content/debuglog.log' );
	        }
	    // }
	}
}

new HMNetworkAutoPost();