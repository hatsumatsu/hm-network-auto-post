<?php

/*
Plugin Name: HM Network Auto Post
Version: 0.20.1
Description: Automatically creates post copies on remote sites in the same network and builds MLP relations when possible.
Plugin URI:
Author: Martin Wecke
Author URI: http://martinwecke.de/
GitHub Plugin URI: https://github.com/hatsumatsu/hm-network-auto-post
GitHub Branch: master
*/


class HMNetworkAutoPost {
	protected $mpl_api_cache;
	protected $settings;

	public function __construct() {
		// i11n
		add_action( 'init', array( $this, 'loadI88n' ) );

		// cache MPL API
		add_action( 'inpsyde_mlp_loaded', array( $this, 'cacheMLPAPI' ) );

		// load settings
		add_action( 'after_setup_theme', array( $this, 'loadSettings' ) );

		// attach post saving action 
		// use `save_post` to include ACF fields which are available later than `publish_post` 
		add_action( 'save_post', array( $this, 'savePost' ), 100, 2 );			

		// use ACF's own acf/save_post hook add a priority > 10 to include repeater field data
		if( class_exists( 'acf' ) ) {
			add_action( 'acf/save_post', array( $this, 'ACFSavePost' ), 9999 );	
		}

		// init meta box
		add_action( 'load-post.php', array( $this, 'initMetabox' ) );
		add_action( 'load-post-new.php', array( $this, 'initMetabox' ) );

		// add admin CSS
		add_action( 'admin_enqueue_scripts', array( $this, 'adminCSS' ) );

		// default settings
		$this->settings = array(
			'post' => array(
				'post_status' 		=> 'publish',
				'post_thumbnail' 	=> true,
				'meta' 				=> array(
					'custom' => true
				),
				'meta-relations' => array(),
				'permanent' => array()
			)
		);
	}


	/**
	 * Load i88n
	 */
	public function loadI88n() {
		load_plugin_textdomain( 'hm-network-auto-post', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );	
	}


	/**
	 * Load settings from filter 'hmnap/settings'
	 */
	public function loadSettings() {
		$this->settings = apply_filters( 'hmnap/settings', $this->settings );
	}


	/**
	 * Cache MultilingualPress API
	 */
	public function cacheMLPAPI( $data ) {
		$this->mpl_api_cache = $data;
	}


	/**
	 * Action to call when posts are saved
	 * @param int $source_post_id Source post ID
	 * @param WP_Post $post Source post
	 * @param bool $update Whether this is an existing post being updated or not.
	 */
	public function savePost( $source_post_id, $source_post ) {
		$this->writeLog( 'savePost()' );
		$this->writeLog( $source_post_id );


		// quit if post is post revisions
		if( wp_is_post_revision( $source_post_id ) ) {
			$this->writeLog( 'Ignore post revision...' );
			return;
		}

		// quit if post is trash or auto draft
		if( $source_post->post_status == 'trash' || $source_post->post_status == 'draft' || $source_post->post_status == 'auto-draft' ) {
			$this->writeLog( 'Ignore drafts, auto drafts or trashed posts...' );
			return;
		}

		// quit if post type is not within settings
		if( !array_key_exists( $source_post->post_type, $this->settings ) ) {
			$this->writeLog( 'Ignore post type...' );
			return;
		}

		// quit if post is marked not to sync
		if( get_post_meta( $source_post_id, 'hmnap--ignore', true ) ) {
			$this->writeLog( 'Ignore post by marker...' );
			return;
		}

		$sites = wp_get_sites();
		$source_site_id = get_current_blog_id();

		// get existing MLP relations
		// Array( [site_id] => [post_id] ) including source site
		$relations = mlp_get_linked_elements( $source_post_id, '', $source_site_id );
		$this->writeLog( 'Existing post relations: ' );
		$this->writeLog( $relations );
		
		// flag source as synced
		update_post_meta( $source_post_id, '_network-auto-post--synced', 1 );

		// each site... 
		foreach( $sites as $site ) {
			$target_site_id = $site['blog_id'];

			// skip current site
			if( $target_site_id == $source_site_id ) {
				continue;
			}

			$is_new = ( array_key_exists( $target_site_id, $relations ) ) ? false : true;

			// no MLP related post exists in target site
			if( $is_new ) {
				$target_post_id = $this->createPost( $source_post, $source_site_id, $source_post_id, $target_site_id );
			// MLP related post on target site exists
			} else {
				$target_post_id = $relations[$target_site_id];
			}


			/**
			 * Set title 
			 * only if title setting is permanent 
			 */
			if( !$is_new ) {
				if( array_key_exists( 'post_title', $this->settings[$source_post->post_type]['permanent'] ) && $this->settings[$source_post->post_type]['permanent']['post_title'] ) {
					$this->setPostTitle( $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}
			}

			/**
			 * Set content
			 */
			if( $is_new ) {
				if( array_key_exists( 'post_content', $this->settings[$source_post->post_type] ) && $this->settings[$source_post->post_type]['post_content'] ) {
					$this->setPostContent( $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}
			} else {
				// check if target content is empty
				switch_to_blog( $target_site_id );
				$target_post = get_post( $target_post_id );
				restore_current_blog();

				if( array_key_exists( 'post_content', $this->settings[$source_post->post_type]['permanent'] ) && $this->settings[$source_post->post_type]['permanent']['post_content']
				 || array_key_exists( 'post_content', $this->settings[$source_post->post_type] ) && $this->settings[$source_post->post_type]['post_content'] === 'not_empty' && empty( trim( $target_post->post_content ) ) ) {
					$this->setPostContent( $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}	
			}


			/**
			 * Set thumbnail image
			 */
			if( $is_new ) {
				if( array_key_exists( 'post_thumbnail', $this->settings[$source_post->post_type] ) && $this->settings[$source_post->post_type]['post_thumbnail'] ) {
					$this->setPostThumbnail( $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}
			} else {
				if( array_key_exists( 'post_thumbnail', $this->settings[$source_post->post_type]['permanent'] ) && $this->settings[$source_post->post_type]['permanent']['post_thumbnail'] ) {
					$this->setPostThumbnail( $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}
			}


			/**
			 * Set tags, cetagories and custom taxonomies
			 */
			if( $is_new ) {
				if( array_key_exists( 'taxonomies', $this->settings[$source_post->post_type] ) && $this->settings[$source_post->post_type]['taxonomies'] ) {
					$this->setTaxonomies( $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}
			} else {
				if( array_key_exists( 'taxonomies', $this->settings[$source_post->post_type]['permanent'] ) && $this->settings[$source_post->post_type]['permanent']['taxonomies'] ) {
					$this->setTaxonomies( $source_site_id, $source_post_id, $target_site_id, $target_post_id );				
				}				
			}


			/**
			 * Find all MLP related uploads of the source post's uploads
			 * and set their post parent to the created post
			 */
			$this->setAttachments( $source_site_id, $source_post_id, $target_site_id, $target_post_id );


			/**
			 * Meta + meta relations:
			 * Copy all meta fields defined in settings
			 */
			if( $is_new ) {
				if( array_key_exists( 'meta', $this->settings[$source_post->post_type] ) && $this->settings[$source_post->post_type]['meta'] ) {
					$fields = $this->settings[$source_post->post_type]['meta'];
					$this->setMeta( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}

				if( array_key_exists( 'meta-relations', $this->settings[$source_post->post_type] ) && $this->settings[$source_post->post_type]['meta-relations'] ) {
					$fields = $this->settings[$source_post->post_type]['meta-relations'];
					$this->setMetaRelations( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}
			} else {
				if( array_key_exists( 'meta', $this->settings[$source_post->post_type]['permanent'] ) && $this->settings[$source_post->post_type]['permanent']['meta'] ) {
					$fields = $this->settings[$source_post->post_type]['permanent']['meta'];
					$this->setMeta( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				} 			

				if( array_key_exists( 'meta-relations', $this->settings[$source_post->post_type]['permanent'] ) && $this->settings[$source_post->post_type]['permanent']['meta-relations'] ) {
					$fields = $this->settings[$source_post->post_type]['permanent']['meta-relations'];
					$this->setMetaRelations( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				}
			}


			/**
			 * ACF fields:
			 * Copy all ACF fields defined in settings
			 * Exception: post relations in subfields...
			 * Exception: sync is always permanent
			 */
			if( $is_new ) {			
				if( array_key_exists( 'acf', $this->settings[$source_post->post_type] ) && $this->settings[$source_post->post_type]['acf'] ) {
					$fields = $this->settings[$source_post->post_type]['acf'];
					$this->setACF( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				} 
			} else {
				if( array_key_exists( 'acf', $this->settings[$source_post->post_type]['permanent'] ) && $this->settings[$source_post->post_type]['permanent']['acf'] ) {
					$fields = $this->settings[$source_post->post_type]['permanent']['acf'];
					$this->setACF( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id );
				} 
			} 	


			/**
			 * Call custom action: save post
			 */
			do_action( 
				'hmnap/save_post',
				$source_site_id,
				$target_site_id,
				$source_post_id,
				$target_post_id
			);				
		}
	}


	/**
	 * Create new post in remote site
	 * @param array $post original post to copy
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @return int $target_post_id post ID of target post
	 */
	public function createPost( $source_post, $source_site_id, $source_post_id, $target_site_id ) {
		$this->writeLog( 'createPost()' );

		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}

		$post_status = ( $this->settings[$source_post->post_type]['post_status'] ) ? $this->settings[$source_post->post_type]['post_status'] : 'draft';
		$post_data = array(
			'ID' 				=> 0,
			'post_title' 		=> $source_post->post_title,
			'post_date' 		=> $source_post->post_date,
			'post_modified' 	=> $source_post->post_modified,
			'post_modified_gmt' => $source_post->post_modified_gmt,
			'post_status' 		=> $post_status,
			'post_author' 		=> $source_post->post_author,
			'post_excerpt'  	=> $source_post->post_excerpt,
			'post_type' 		=> $source_post->post_type,
			'post_name' 		=> $source_post->post_name
		);

		// remove save_post action to avoid infinite loop
		remove_action( 'save_post', array( $this, 'savePost' ), 100 );	
		// switch to target site
		switch_to_blog( $target_site_id );
		// create post
		$target_post_id = wp_insert_post( $post_data );
		// switch back to source site
		restore_current_blog();
		// re-add save_post action
		add_action( 'save_post', array( $this, 'savePost' ), 100, 2 );	


		/**
		 * Set MultilingualPress relation
		 */
		$this->writeLog( 'There is no MLP relation yet in site: ' . $target_site_id );

		if( $this->mpl_api_cache ) {            
			$relations = $this->mpl_api_cache->get( 'content_relations' );

			$relations->set_relation(
				$source_site_id,
				$target_site_id,
				$source_post_id,
				$target_post_id
			);
		}


		/**
		 * Call custom action: create post
		 */
		do_action( 
			'hmnap/create_post',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);	

		return $target_post_id;
	}


	/**
	 * Set post title of remote post
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @param int $target_post_id post ID of target post
	 */
	public function setPostTitle( $source_site_id, $source_post_id, $target_site_id, $target_post_id ) {
		$this->writeLog( 'setPostTitle()' );

		$source_post = get_post( $source_post_id );
		if( !$source_post ) {
			return;
		}

		$post_data = array(
      		'ID'           => $target_post_id,
      		'post_title'   => $source_post->post_title,
      		'post_name'    => $source_post->post_name
		);

		// remove save_post action to avoid infinite loop
		remove_action( 'save_post', array( $this, 'savePost' ), 100 );
		// switch to target site
		switch_to_blog( $target_site_id );
		// update post data
  		wp_update_post( $post_data );
		// switch back to source site
		restore_current_blog();
		// re-add save_post action
		add_action( 'save_post', array( $this, 'savePost' ), 100, 2 );


		/**
		 * Call custom action: set post title
		 */
		do_action( 
			'hmnap/set_post_title',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);		
	}


	/**
	 * Set post content of remote post
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @param int $target_post_id post ID of target post
	 */
	public function setPostContent( $source_site_id, $source_post_id, $target_site_id, $target_post_id ) {
		$this->writeLog( 'setPostContent()' );
		
		$source_post = get_post( $source_post_id );
		if( !$source_post ) {
			return;
		}

		$post_data = array(
      		'ID'           => $target_post_id,
      		'post_content' => $source_post->post_content,
      		'post_excerpt' => $source_post->post_excerpt
		);

		// remove save_post action to avoid infinite loop
		remove_action( 'save_post', array( $this, 'savePost' ), 100 );
		// switch to target site
		switch_to_blog( $target_site_id );
		// update post data
  		wp_update_post( $post_data );
		// switch back to source site
		restore_current_blog();
		// re-add save_post action
		add_action( 'save_post', array( $this, 'savePost' ), 100, 2 );	


		/**
		 * Call custom action: set post content
		 */
		do_action( 
			'hmnap/set_post_content',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);		
	}	


	/**
	 * Set post thumbnail of remote post
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @param int $target_post_id post ID of target post
	 */
	public function setPostThumbnail( $source_site_id, $source_post_id, $target_site_id, $target_post_id ) {
		$this->writeLog( 'setPostThumbnail()' );

		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}

		// if source post has thumbnail
		if( $source_post_thumbnail_id = get_post_thumbnail_id( $source_post_id ) ) {
			$this->writeLog( 'Thumbnail found' );

			// check for the thumbnail's MLP relations
			$thumbnail_relations = mlp_get_linked_elements( $source_post_thumbnail_id, '', $source_site_id );
			if( array_key_exists( $target_site_id, $thumbnail_relations ) ) {
				$this->writeLog( 'Thumbnail relation found: ' . $thumbnail_relations[$target_site_id] );

				// switch to target site
				switch_to_blog( $target_site_id );
				// set thumbnail on target site
				update_post_meta( $target_post_id, '_thumbnail_id', $thumbnail_relations[$target_site_id] );
				// switch back to source site
				restore_current_blog();
			} else {
				// switch to target site
				switch_to_blog( $target_site_id );
				// set thumbnail on target site
				delete_post_meta( $target_post_id, '_thumbnail_id' );
				// switch back to source site
				restore_current_blog();				
			}
		} else {
			// switch to target site
			switch_to_blog( $target_site_id );
			// set thumbnail on target site
			delete_post_meta( $target_post_id, '_thumbnail_id' );
			// switch back to source site
			restore_current_blog();				
		}


		/**
		 * Call custom action: set post thumbnail
		 */
		do_action( 
			'hmnap/set_post_thumbnail',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);			
	}


	/**
	 * Set taxonomies of remote posts
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @param int $target_post_id post ID of target post
	 */
	public function setTaxonomies( $source_site_id, $source_post_id, $target_site_id, $target_post_id ) {
		$this->writeLog( 'setTaxonomies()' );

		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}

		if( $taxonomies = get_object_taxonomies( get_post_type( $source_post_id ), 'names' ) ) {
			foreach( $taxonomies as $taxonomy ) {
				if( $terms = get_the_terms( $source_post_id, $taxonomy ) ) {
					$target_terms = array();

					foreach( $terms as $term ) {
						// https://github.com/inpsyde/multilingual-press/issues/199
						$content_relations = $this->mpl_api_cache->get( 'content_relations' );
						$term_relations = $content_relations->get_relations( $source_site_id, $term->term_id, 'term' );

						$this->writeLog( 'related terms: ' );
						$this->writeLog( $term_relations );

						if( array_key_exists( $target_site_id, $term_relations ) ) {
							$target_terms[] = $term_relations[$target_site_id];
						}
					}

					if( $target_terms ) {
						// switch to target site
						switch_to_blog( $target_site_id );
						// set terms
						wp_set_post_terms( $target_post_id, $target_terms, $taxonomy, false );
						// switch back to source site
						restore_current_blog();
					}
				}
			}
		}


		/**
		 * Call custom action: set taxonomies
		 */
		do_action( 
			'hmnap/set_taxonomies',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);		
	}


	/**
	 * Set attachment parent post IDs on remote posts
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @param int $target_post_id post ID of target post
	 */
	public function setAttachments( $source_site_id, $source_post_id, $target_site_id, $target_post_id ) {
		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}		

		$attachments = get_posts(
			array(
				'post_type' 		=> 'attachment',
				'posts_per_page' 	=> -1,
				'post_parent' 		=> $source_post_id
			)
		);

		if( $attachments ) {
			foreach( $attachments as $attachment ) {
				$attachment_relations = mlp_get_linked_elements( $attachment->ID, '', $source_site_id );
				if( array_key_exists( $target_site_id, $attachment_relations ) ) {
					// switch to target site
					switch_to_blog( $target_site_id );
					// set attachments' post parent
					if( $target_attachment = get_post( $attachment_relations[$target_site_id] ) ) {
						$this->writeLog( $target_attachment );

						if( !$target_attachment->post_parent ) {
							$target_attachment_data = array(
								'ID' 			=> $target_attachment->ID,
								'post_parent' 	=> $target_post_id
							);

							wp_update_post( $target_attachment_data );
						}
					}
					// switch back to source post
					restore_current_blog();
				}
			}
		}


		/**
		 * Call custom action: set attachments
		 */
		do_action( 
			'hmnap/set_attachments',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);			
	}


	/**
	 * Set meta fields
	 * @param array $fields array of meta fields to copy
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @param int $target_post_id post ID of target post
	 */
	public function setMeta( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id ) {
		$this->writeLog( 'setMeta()' );
		$this->writeLog( $fields );		

		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}

		if( $fields ) {				
			foreach( $fields as $key => $state ) {
				if( $state ) {
					$value = get_post_meta( $source_post_id, $key, true );
					if( $value ) {		
						// switch to target site
						switch_to_blog( $target_site_id );											
						// update meta								
						update_post_meta( $target_post_id, $key, $value );								
						// switch back to source post
						restore_current_blog();						
					} else {
						// switch to target site
						switch_to_blog( $target_site_id );											
						// update meta								
						delete_post_meta( $target_post_id, $key );								
						// switch back to source post
						restore_current_blog();						
					}
				}
			}		
		}


		/**
		 * Call custom action: set meta
		 */
		do_action( 
			'hmnap/set_meta',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);			
	}	


	/**
	 * Set meta fields that are post relations
	 * TODO: splt into setMeta() and setMetaRelations()
	 * @param array $fields array of meta fields to copy 
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @param int $target_post_id post ID of target post
	 */
	public function setMetaRelations( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id ) {
		$this->writeLog( 'setMetaRelations()' );
		$this->writeLog( $fields );
		
		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}

		if( $fields ) {
			foreach( $fields as $key => $state ) {
				$value = get_post_meta( $source_post_id, $key, true );
				$this->writeLog( $value );

				if( $value ) {		

					/**
					 * TODO: Handle multiple data of the same key
					 */
					
					/**
					 * Serialized data
					 */
					if( is_array( $value ) ) {

						foreach( $value as $k => $v ) {
													
							$meta_relations = mlp_get_linked_elements( $v, '', $source_site_id );
							if( array_key_exists( $target_site_id, $meta_relations ) ) {
								$value[$k] = $meta_relations[$target_site_id];
							} else {
								unset( $value[$k] );
							}
						}		

						// switch to target site
						switch_to_blog( $target_site_id );	
						// update meta								
						update_post_meta( $target_post_id, $key, $value );								
						// switch back to source post
						restore_current_blog();							
					/**
					 * not serialied data
					 */	
					} else {
						$meta_relations = mlp_get_linked_elements( $value, '', $source_site_id );
						if( array_key_exists( $target_site_id, $meta_relations ) ) {
							$this->writeLog( 'meta relation found' );

							// switch to target site
							switch_to_blog( $target_site_id );	
							// update meta								
							update_post_meta( $target_post_id, $key, $meta_relations[$target_site_id] );								
							// switch back to source post
							restore_current_blog();
						} else {
							// switch to target site
							switch_to_blog( $target_site_id );											
							// update meta								
							delete_post_meta( $target_post_id, $key );								
							// switch back to source post
							restore_current_blog();							
						}
					}
				} else {
					// switch to target site
					switch_to_blog( $target_site_id );											
					// update meta								
					delete_post_meta( $target_post_id, $key );								
					// switch back to source post
					restore_current_blog();						
				}
			}
		}


		/**
		 * Call custom action: set meta relations
		 */
		do_action( 
			'hmnap/set_meta_relations',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);	
	}


	/**
	 * Set ACF repeater field data
	 * @param array $fields array of meta fields to copy
	 * @param int $source_site_id site ID of source site
	 * @param int $source_post_id post ID of source post
	 * @param int $target_site_id site ID of target site
	 * @param int $target_post_id post ID of target post
	 */
	public function setACF( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id ) {
		$this->writeLog( 'setACF()' );
		$this->writeLog( $fields );		

		if( !class_exists( 'acf' ) ) {
			return;
		}

		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}

		if( $fields ) {				
			foreach( $fields as $key => $state ) {
				$this->writeLog( 'key: ' . $key . ' state: ' . $state );

				if( $state ) {
					$SourceFieldObject = get_field_object( $key, $source_post_id, false );
					$value = $SourceFieldObject['value'];

					$this->writeLog( $SourceFieldObject );

					if( $value ) {		
						// switch to target site
						switch_to_blog( $target_site_id );	

						$TargetFieldObject = get_field_object( $key, $target_post_id );
						$this->writeLog( $TargetFieldObject );			

						// update meta								
						update_field( $key, $value, $target_post_id );								
						// switch back to source post
						restore_current_blog();
					} else {
						// switch to target site
						switch_to_blog( $target_site_id );											
						// update meta								
						delete_field( $key, $target_post_id );								
						// switch back to source post
						restore_current_blog();	
					}
				}
			}		
		}


		/**
		 * Call custom action: set meta
		 */
		do_action( 
			'hmnap/set_acf',
			$source_site_id,
			$target_site_id,
			$source_post_id,
			$target_post_id
		);			
	}	


	/**
	 * Action to call on acf/save_post hook
	 * @param int $source_post_id Source post ID
	 * @param bool $update Whether this is an existing post being updated or not.
	 */
	public function ACFSavePost( $source_post_id ) {
		$this->writeLog( 'ACFSavePost()' );
		$this->writeLog( $source_post_id );

		$source_post = get_post( $source_post_id );

		// quit if post is post revisions
		if( wp_is_post_revision( $source_post_id ) ) {
			$this->writeLog( 'Ignore post revision...' );
			return;
		}

		// quit if post is trash or auto draft
		if( $source_post->post_status == 'trash' || $source_post->post_status == 'draft' || $source_post->post_status == 'auto-draft' ) {
			$this->writeLog( 'Ignore drafts, auto drafts or trashed posts...' );
			return;
		}

		// quit if post type is not within settings
		if( !array_key_exists( $source_post->post_type, $this->settings ) ) {
			$this->writeLog( 'Ignore post type...' );
			return;
		}

		// quit if post is marked not to sync
		if( get_post_meta( $source_post_id, 'hmnap--ignore', true ) ) {
			$this->writeLog( 'Ignore post by marker...' );
			return;
		}

		$sites = wp_get_sites();
		$source_site_id = get_current_blog_id();

		// get existing MLP relations
		// Array( [site_id] => [post_id] ) including source site
		$relations = mlp_get_linked_elements( $source_post_id, '', $source_site_id );
		$this->writeLog( 'Existing post relations: ' );
		$this->writeLog( $relations );
		

		// each site... 
		foreach( $sites as $site ) {
			$target_site_id = $site['blog_id'];

			// skip current site
			if( $target_site_id == $source_site_id ) {
				continue;
			}

			$target_post_id = $relations[$target_site_id];


			/**
			 * ACF fields:
			 * Copy all ACF fields defined in settings
			 * Exception: post relations in subfields...
			 * Exception: sync is always permanent
			 */
			if( array_key_exists( 'acf', $this->settings[$source_post->post_type]['permanent'] ) && $this->settings[$source_post->post_type]['permanent']['acf'] ) {
				$fields = $this->settings[$source_post->post_type]['permanent']['acf'];
				$this->setACF( $fields, $source_site_id, $source_post_id, $target_site_id, $target_post_id );
			} 					


			/**
			 * Call custom action: save post
			 */
			do_action( 
				'hmnap/acf/save_post',
				$source_site_id,
				$target_site_id,
				$source_post_id,
				$target_post_id
			);				
		}
	}	


	/**
	 * Meta box initialization.
	 */
	public function initMetabox() {
		add_action( 'add_meta_boxes', array( $this, 'addMetabox' ) );
	}		


	/**
	 * Adds the meta box.
	 */
	public function addMetabox() {
		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}

		$screens = array();
		foreach( $this->settings as $post_type => $settings ) {
			$screens[] = $post_type;
		}

		add_meta_box(
			'hm-network-auto-post',
			__( 'Translations', 'hm-network-auto-post' ),
			array( $this, 'renderMetabox' ),
			$screens,
			'advanced',
			'default'
		);
	}


	/**
	 * Renders the meta box.
	 * @param WP_Post $post post
	 */
	public function renderMetabox( $post ) {
		if( !function_exists( 'mlp_get_linked_elements' ) ) {
			return;
		}

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
					echo '<a href="' . esc_url( get_edit_post_link( $relation ) ) . '">';
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


	/**
	 * Register admin CSS
	 */
	public function adminCSS() {
		wp_register_style( 'hmnap-admin-style', WP_PLUGIN_URL . '/hm-network-auto-post/css/hm-network-auto-post-admin.css' );
		wp_enqueue_style( 'hmnap-admin-style' );
	} 


	/**
	 * Write log if WP_DEBUG is active
	 * @param  string|array $log 
	 */
	public function writeLog( $log )  {
		if( true === WP_DEBUG ) {
			if( is_array( $log ) || is_object( $log ) ) {
				error_log( 'HMNAP: ' . print_r( $log, true ) . "\n", 3, trailingslashit( ABSPATH ) . 'wp-content/debuglog.log' );
			} else {
				error_log( 'HMNAP: ' . $log . "\n", 3, trailingslashit( ABSPATH ) . 'wp-content/debuglog.log' );
			}
		}
	}
}

new HMNetworkAutoPost();