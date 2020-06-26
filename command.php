<?php

if(!class_exists('WP_CLI')) {
	return;
}

// force core loading importers
define('WP_LOAD_IMPORTERS', true);

// class definition
WP_CLI::add_hook('after_add_command:import', function ()
{
	class Import_Plus extends WP_CLI_Command
	{
		private $terms = array();
		private $post_metas = array();
		private $skip_terms = array();

		/**
		 * Allow extra operations when importing posts via a WXR file generated by the Wordpress export feature.
		 *
		 * ## EXTRA OPERATIONS
		 *
		 * Associate extra terms and post meta to each post imported.
		 *
		 * Skip categories and tags import.
		 *
		 * ## OPTIONS
		 *
		 * <file>
		 * : Path to a valid WXR files for importing. Directories are also accepted..
		 *
		 * [--authors=<authors>]
		 * : How the author mapping should be handled. Options are ‘create’, ‘mapping.csv’, or ‘skip’. The first will create any
		 *   non-existent users from the WXR file. The second will read author mapping associations from a CSV, or create a CSV
		 *   for editing if the file path doesn’t exist. The CSV requires two columns, and a header row like “old_user_login,new_user_login”.
		 *   The last option will skip any author mapping.
		 *
		 * [--skip=<data-type>]
		 * : Skip importing specific data. Supported options are: ‘attachment’ and ‘image_resize’ (skip time-consuming thumbnail generation).
		 *
		 * [--extra-categories=<IDs>]
		 * : Comma-separated list of categories IDs to associate to each imported post.
		 *
		 * [--extra-tags=<slugs>]
		 * : Comma-separated list of post_tag slugs to associate to each imported post.
		 *
		 * [--extra-custom-terms-taxonomy=<taxonomy-name>]
		 * : The taxonomy of the extra terms to associate to each imported post. If not set the extra-custom-terms parameter will be ignored.
		 *
		 * [--extra-custom-terms=<IDs-or-slugs>]
		 * : Comma-separated list of terms to associate to each imported post. If you want to enter terms of a hierarchical taxonomy like
		 * 	categories, then use IDs. If you want to add non-hierarchical terms like tags, then use names.
		 *  The parameter will be ignored if extra-custom-terms-taxonomy isn't set.
		 *
		 * [--extra-post-meta-keys=<post-meta-keys>]
		 * : Comma-separated list of post-meta keys to associate to each imported post.
		 *
		 * [--extra-post-meta-values=<post-meta-values>]
		 * : Comma-separated list of post-meta value to associate to each imported post.
		 * 	The values will be assigned respectively in the same order to the keys specified in --extra-post-meta-keys.
		 *
		 * [--skip-categories]
		 * : If set categories will not be imported, except for those set with --extra-categories
		 *
		 * [--skip-tags]
		 * : If set tags will not be imported, except for those set with --extra-tags
		 *
		 * [--profile]
		 * : Print a table with queries information made for each post
		 *
		 * ---
		 * default: success
		 * options:
		 *   - success
		 *   - error
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp import-plus --extra-tags=imported --extra-post-meta-keys=imported_post,custom_meta --extra-post-meta-values=yes,example export.xml
		 *
		 * @when after_wp_load
		 */
		public function __invoke($args, $assoc_args)
		{
			// recupero dati extra
			$this->orderTerms($assoc_args);
			$this->orderPostMetas($assoc_args);

			// hook on saved post
			add_action('save_post', array($this, 'associateExtraData'), 10, 2);

			if(isset($assoc_args['profile'])) {
				define('SAVEQUERIES', true);
				add_filter('wp_import_post_data_raw', array($this, 'startProfiling'), 1);
				add_filter('wp_import_post_meta', array($this, 'endProfiling'), 10000);
			}

			// skip terms
			if(isset($assoc_args['skip-categories'])) {
				$this->skip_terms[] = 'category';
				add_filter('wp_import_categories', '__return_empty_array');
			}

			if(isset($assoc_args['skip-tags'])) {
				$this->skip_terms[] = 'post_tag';
				add_filter('wp_import_tags', '__return_empty_array');
			}

			if(!empty($this->skip_terms)) {
				add_filter('wp_import_post_terms', array($this, 'manageTerms'), 10);
			}

			// launch import command
			$import_args = array_intersect_key($assoc_args, array('authors' => '', 'skip' => '', 'url' => '')); // only arguments allowed for wp import command
			WP_CLI::run_command(array_merge(array('import'), $args), $import_args);
			WP_CLI::success('ok');
		}

		private function orderTerms($assoc_args)
		{
			// categories
			if(!empty($assoc_args['extra-categories'])) {
				$this->terms['category'] = explode(',', $assoc_args['extra-categories']);
			}

			// tags
			if(!empty($assoc_args['extra-tags'])) {
				$this->terms['post_tag'] = explode(',', $assoc_args['extra-tags']);
			}

			// custom taxonomy
			if(!empty($assoc_args['extra-custom-terms-taxonomy'])) {

				if(!taxonomy_exists($assoc_args['extra-custom-terms-taxonomy'])) {
					WP_CLI::error('Unexisting custom taxonomy', true);
				} else if(!empty($assoc_args['extra-custom-terms'])) {
					$this->terms[$assoc_args['extra-custom-terms-taxonomy']] = explode(',', $assoc_args['extra-custom-terms']);
				}
			}
		}

		private function orderPostMetas($assoc_args)
		{
			if(empty($assoc_args['extra-post-meta-keys']) || empty($assoc_args['extra-post-meta-values'])) {
				return;
			}

			$keys = explode(',', $assoc_args['extra-post-meta-keys']);
			$values = explode(',', $assoc_args['extra-post-meta-values']);

			foreach($keys as $i => $key) {

				if(empty($values[$i])) {
					return;
				}

				$this->post_metas[$key] = $values[$i];
			}
		}

		public function associateExtraData($post_id, $post)
		{
			if(wp_is_post_revision($post)) {
				return;
			}

			// post metas
			if(!empty($this->post_metas)) {
				foreach($this->post_metas as $key => $value) {
					WP_CLI::line('-- PLUS: Setting post meta ' . $key);
					add_post_meta($post_id, $key, $value);
				}
			}
		}

		public function manageTerms($terms)
		{
			$taxonomies_to_skip = $this->skip_terms;

			// skipping taxonomies
			$terms = array_filter($terms, function ($term) use ($taxonomies_to_skip)
			{
				$taxonomy = ('tag' == $term['domain']) ? 'post_tag' : $term['domain'];
				return !in_array($taxonomy, $taxonomies_to_skip);
			});

			// adding extra terms
			if(!empty($this->terms)) {
				foreach($this->terms as $taxonomy => $extraTerms) {
					$domain = ('tag' == $taxonomy) ? 'post_tag' : $taxonomy;
					foreach ($extraTerms as $term) {

						if(is_taxonomy_hierarchical($taxonomy)) {
							$extra_term = get_term($term, $taxonomy);
							$slug = empty($extra_term) ? $term : $extra_term->slug;
						} else {
							$slug = $term;
						}

						$terms[] = array(
							'domain' 	=> $domain,
							'slug'		=> $slug,
							'name'		=> $slug // if term not exists will be created with this name
						);
					}

				}
			}

			return $terms;
		}

		public function startProfiling($raw)
		{
			global $wpdb;
			$wpdb->queries = array();

			return $raw;
		}

		public function endProfiling($thing)
		{
			global $wpdb;

			$table = array();
			foreach($wpdb->queries as $query) {
				$table[] = array(
					'QUERY' => $query[0],
					'TIME' => $query[1],
					'TRACE' => $query[2],
				);
			}

			WP_CLI\Utils\format_items('yaml', $table, array('QUERY', 'TIME', 'TRACE'));

			return $thing;
		}
	}

	// command definition
	WP_CLI::add_command('import-plus', 'Import_Plus');
});
