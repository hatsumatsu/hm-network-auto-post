<?php

/*
Plugin Name: HM Network Auto Post
Version: 0.12
Description: Automatically create post copies on remote sites in the same network and create MLP relations when possible.
Plugin URI:
Author: Martin Wecke, HATSUMATSU
Author URI: http://hatsumatsu.de/
GitHub Plugin URI: https://github.com/hatsumatsu/hm-network-auto-post
GitHub Branch: master
*/


class HMNetworkAutoPost {
	protected $mpl_api_cache;
	protected $settings;

	public function __construct() {
		// i11n
		add_action( 'init', array( $this, 'load_i88n' ) );

		// cache MPL API
		add_action( 'inpsyde_mlp_loaded', array( $this, 'cache_mlp_api' ) );

		// load settings
		add_action( 'after_setup_theme', array( $this, 'load_settings' ) );

		// attach publish action 
		// use `save_post` to include ACF fields which are available later than `pulbish_post` 
		add_action( 'save_post', array( $this, 'copy_post' ), 100, 2 );			

		// init meta box
		add_action( 'load-post.php', array( $this, 'init_metabox' ) );
		add_action( 'load-post-new.php', array( $this, 'init_metabox' ) );

		// add admin CSS
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_css' ) );

		// default settings
		$this->settings = array(
			'post' => array(
				'post_status' 		=> 'publish',
				'post_thumbnail' 	=> true,
				'meta' 				=> array(
					'custom'
				)
			)
		);
	}


	/**
	 * Load i88n
	 */
	public function load_i88n() {
		load_plugin_textdomain( 'hm-network-auto-post', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );	
	}


	/**
	 * Load settings from filter 'hmnap/settings'
	 */
	public function load_settings() {
		$this->settings = apply_filters( 'hmnap/settings', $this->settings );
	}


	/**
	 * Cache MultilingualPress API
	 */
	public function cache_mlp_api( $data ) {
		$this->write_log( 'inpsyde_mlp_loaded' );

		$this->mpl_api_cache = $data;
	}


	/**
	 * Copy posts 
	 * @param int $post_id Source post ID
	 * @param WP_Post $post Source post
	 * @param bool $update Whether this is an existing post being updated or not.
	 */
	public function copy_post( $post_id, $post ) {
		// quit if post is post revisions
		if( wp_is_post_revision( $post_id ) ) {
			$this->write_log( 'ignore revision' );
			return;
		}

		// quit if post is trash or auto draft
		if( $post->post_status == 'trash' || $post->post_status == 'draft' || $post->post_status == 'auto-draft' ) {
			$this->write_log( 'ignore auto draft or trash' );
			return;
		}

		$this->write_log( 'save_post' );
		$this->write_log( $post_id );
		$this->write_log( $post->post_title );

		// if post type is within settings
		if( array_key_exists( $post->post_type, $this->settings ) ) {
			$this->write_log( 'post type is within current settings' );

			$sites = wp_get_sites();
			$source_site_id = get_current_blog_id();

			// get existing MLP relations
			$relations = mlp_get_linked_elements( $post_id, '', $source_site_id );
			$this->write_log( $relations );

			// post is not synced / MLP related yet
			if( !get_post_meta( $post_id, '_network-auto-post--synced', true ) && !$relations ) {
				$this->write_log( 'post is not yet synced by HMNAP' );		

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

				// copy all meta fields defined in settings
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

				// each site... 
				foreach( $sites as $site ) {
					// ... that is not the source site
					if( $site['blog_id'] != $source_site_id ) {
						$this->write_log( 'Copy post to remote site id: ' . $site['blog_id'] );
	

						/**
						 * Create post
						 */
						// switch to target site
						switch_to_blog( $site['blog_id'] );
						// create post
						$copy_id = wp_insert_post( $post_data );
						// switch back to source site
						restore_current_blog();


						/**
						 * Set MultilingualPress relation
						 */
						// if MultilingualPress is present
						if( function_exists( 'mlp_get_linked_elements' ) ) {
							// if there are no MLP related posts
							if( !array_key_exists( $site['blog_id'], $relations ) ) {
								$this->write_log( 'There is no MLP relation yet in site: ' . $site['blog_id'] );

								if( $this->mpl_api_cache ) {            
									$relations = $this->mpl_api_cache->get( 'content_relations' );

									$relations->set_relation(
										$source_site_id,
										$site['blog_id'],
										$post_id,
										$copy_id
									);
								}
							}
						}


						/**
						 * Set thumbnail image
						 */
						// if source post has thumbnail
						if( $source_post_thumbnail_id = get_post_thumbnail_id( $post_id ) ) {
							$this->write_log( 'Thumbnail found' );

							// check for the thumbnail's MLP relations
							$thumbnail_relations = mlp_get_linked_elements( $source_post_thumbnail_id, '', $source_site_id );
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
						 * Call custom action
						 */
						do_action( 
							'hmnap/create_post',
							$source_site_id,
							$site['blog_id'],
							$post_id,
							$copy_id
						);


						/**
						 * Set tags, cetagories and custom taxonomies
						 * Use get_object_taxonomies() on post_type
						 */
						if( function_exists( 'mlp_get_linked_elements' ) ) {
							if( $taxonomies = get_object_taxonomies( $post->post_type, 'names' ) ) {
								foreach( $taxonomies as $taxonomy ) {
									if( $terms = get_the_terms( $post, $taxonomy ) ) {
										$this->write_log( $terms );

										$target_terms = array();

										foreach( $terms as $term ) {
											// https://github.com/inpsyde/multilingual-press/issues/199
											$content_relations = $this->mpl_api_cache->get( 'content_relations' );
											$term_relations = $content_relations->get_relations( $source_site_id, $term->term_id, 'term' );

											$this->write_log( 'related terms: ' );
											$this->write_log( $term_relations );

											if( array_key_exists( $site['blog_id'], $term_relations ) ) {
												$target_terms[] = $term_relations[$site['blog_id']];
											}
										}

										if( $target_terms ) {
											// switch to target site
											switch_to_blog( $site['blog_id'] );
											// set terms
											wp_set_post_terms( $copy_id, $target_terms, $taxonomy, false );
											// switch back to source site
											restore_current_blog();
										}
									}
								}
							}
						}


						/**
						 * Find all MLP related uploads of the source post's uploads
						 * and set their post parent to the created post
						 */
						if( function_exists( 'mlp_get_linked_elements' ) ) {
							$attachments = get_posts(
								array(
									'post_type' 		=> 'attachment',
									'posts_per_page' 	=> -1,
									'post_parent' 		=> $post->ID
								)
							);

							if( $attachments ) {
								foreach( $attachments as $attachment ) {
									$attachment_relations = mlp_get_linked_elements( $attachment->ID, '', $source_site_id );
									if( array_key_exists( $site['blog_id'], $attachment_relations ) ) {
										// switch to target site
										switch_to_blog( $site['blog_id'] );
										// set attachments' post parent
										if( $target_attachment = get_post( $attachment_relations[$site['blog_id']] ) ) {
											$this->write_log( $target_attachment );

											if( !$target_attachment->post_parent ) {
												$target_attachment_data = array(
													'ID' 			=> $target_attachment->ID,
													'post_parent' 	=> $copy_id
												);

												wp_update_post( $target_attachment_data );
											}
										}
										// switch back to source post
										restore_current_blog();
									}
								}
							}
						}


						/**
						 * Meta relations:
						 * Copy all meta fields defined in settings
						 * that are post relations
						 */
						if( $this->settings[$post->post_type]['meta-relations'] ) {
							foreach( $this->settings[$post->post_type]['meta-relations'] as $key ) {
								$value = get_post_meta( $post_id, $key, true );
								if( $value ) {
									$meta_relations = mlp_get_linked_elements( $value, '', $source_site_id );
									if( array_key_exists( $site['blog_id'], $meta_relations ) ) {
										// switch to target site
										switch_to_blog( $site['blog_id'] );	
										// update meta								
										update_post_meta( $copy_id, $key, $meta_relations[$site['blog_id']] );								
										// switch back to source post
										restore_current_blog();
									}
								}
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
	 * @param WP_Post $post post
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
						if( array_key_exists( $site_id, $language_titles ) ) {
							echo $language_titles[ $site_id ];
						}
						echo '</h4>';
						echo '</div>';

						restore_current_blog();
					}
				}
			} else {
				echo '<h3>';
				echo __( 'No translations yet', 'hm-network-auto-post' );
				echo '</h3>';
				echo '<p>';
				echo __( 'Translations are automatically created when this post is published.', 'hm-network-auto-post' );
				echo '</p>';				
			}
		}
	}


	/**
	 * Register admin CSS
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
		if( true === WP_DEBUG ) {
			if( is_array( $log ) || is_object( $log ) ) {
				error_log( 'hmnap: ' . print_r( $log, true ) . "\n", 3, trailingslashit( ABSPATH ) . 'wp-content/debuglog.log' );
			} else {
				error_log( 'hmnap: ' . $log . "\n", 3, trailingslashit( ABSPATH ) . 'wp-content/debuglog.log' );
			}
		}
	}
}

new HMNetworkAutoPost();