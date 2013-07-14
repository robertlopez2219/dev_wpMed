<?php

namespace WPFuncRef;

use WP_CLI;

/**
 * Handles creating and updating posts from (functions|classes|files) generated by phpDoc.
 *
 * Based on the Importer class from https://github.com/rmccue/WP-Parser/
 */
class Importer {

	/**
	 * Taxonony name for files
	 *
	 * @var string
	 */
	public $taxonomy_file;

	/**
	 * Taxonomy name for an item's @since tag
	 *
	 * @var string
	 */
	public $taxonomy_since_version;

	/**
	 * Taxonomy name for an item's @package/@subpackage tags
	 *
	 * @var string
	 */
	public $taxonomy_package;

	/**
	 * Post type name for functions
	 *
	 * @var string
	 */
	public $post_type_function;

	/**
	 * Post type name for classes
	 *
	 * @var string
	 */
	public $post_type_class;

	public $post_type_hook;    // todo

	/**
	 * Handy store for meta about the current item being imported
	 *
	 * @var array
	 */
	public $file_meta = array();

	/**
	 * @var array Human-readable errors
	 */
	public $errors = array();


	/**
	 * Constructor. Sets up post type/taxonomy names.
	 *
	 * @param array $args Optional. Associative array; class property => value.
	 */
	public function __construct( array $args = array() ) {

		$r = wp_parse_args( $args, array(
			'post_type_class'        => 'wpapi-class',
			'post_type_function'     => 'wpapi-function',
			'taxonomy_file'          => 'wpapi-source-file',
			'taxonomy_package'       => 'wpapi-package',
			'taxonomy_since_version' => 'wpapi-since',
		) );

		foreach ( $r as $property_name => $value ) {
			$this->{$property_name} = $value;
		}
	}

	/**
	 * For a specific file, go through and import the file, functions, and classes.
	 *
	 * @param array $file
	 * @param bool $skip_sleep Optional; defaults to false. If true, the sleep() calls are skipped.
	 * @param bool $import_internal Optional; defaults to false. If true, functions and classes marked @internal will be imported.
	 */
	public function import_file( array $file, $skip_sleep = false, $import_internal = false ) {

		// Maybe add this file to the file taxonomy
		$slug = sanitize_title( str_replace( '/', '_', $file['path'] ) );
		$term = get_term_by( 'slug', $slug, $this->taxonomy_file, ARRAY_A );
		if ( ! $term ) {

			$term = wp_insert_term( $file['path'], $this->taxonomy_file, array( 'slug' => $slug ) );
			if ( is_wp_error( $term ) ) {
				$this->errors[] = sprintf( 'Problem creating file tax item "%1$s" for %2$s: %3$s', $slug, $file['path'], $term->get_error_message() );
				return;
			}

			// Grab the full term object
			$term = get_term_by( 'slug', $slug, $this->taxonomy_file, ARRAY_A );
		}

		// Store file meta for later use
		$this->file_meta = array(
			'docblock' => $file['file'],  // File docblock
			'term_id'  => $term['name'],  // File's term item in the file taxonomy
		}

		// Functions
		if ( ! empty( $file['functions'] ) ) {
			$i = 0;

			foreach ( $file['functions'] as $function ) {
				$this->import_function( $function, 0, $import_internal );
				$i++;

				// Wait 3 seconds after every 10 items
				if ( ! $skip_sleep && $i % 10 == 0 )
					sleep( 3 );
			}
		}

		// Classes
		if ( ! empty( $file['classes'] ) ) {
			$i = 0;

			foreach ( $file['classes'] as $class ) {
				$this->import_class( $class, $import_internal );
				$i++;

				// Wait 3 seconds after every 10 items
				if ( ! $skip_sleep && $i % 10 == 0 )
					sleep( 3 );
			}
		}
	}

	/**
	 * Create a post for a function
	 *
	 * @param array $data Function
	 * @param int $class_post_id Optional; post ID of the class this method belongs to. Defaults to zero (not a method).
	 * @param bool $import_internal Optional; defaults to false. If true, functions marked @internal will be imported.
	 * @return bool|int Post ID of this function, false if any failure.
	 */
	public function import_function( array $data, $class_post_id = 0, $import_internal = false ) {
		return $this->import_item( $data, $class_post_id, $import_internal );
	}

	/**
	 * Create a post for a class
	 *
	 * @param array $data Class
	 * @param bool $import_internal Optional; defaults to false. If true, functions marked @internal will be imported.
	 * @return bool|int Post ID of this function, false if any failure.
	 */
	protected function import_class( array $data, $import_internal = false ) {
		global $wpdb;

		// Insert this class
		$class_id = $this->import_item( $data, 0, $import_internal, array( 'post_type' => $this->post_type_class ) );
		if ( ! $class_id )
			return false;

		// Set class-specific meta
		update_post_meta( $class_id, '_wpapi_final',      (bool) $data['final'] );
		update_post_meta( $class_id, '_wpapi_abstract',   (bool) $data['abstract'] );
		update_post_meta( $class_id, '_wpapi_static',     (bool) $data['static'] );
		update_post_meta( $class_id, '_wpapi_visibility',        $data['visibility'] );

		// Now add the methods
		foreach ( $data['methods'] as $method )
			$this->import_item( $method, $class_id, $import_internal );

		return $class_id;
	}

	/**
	 * Create a post for an item (a class or a function).
	 *
	 * Anything that needs to be dealt identically for functions or methods should go in this function.
	 * Anything more specific should go in either import_function() or import_class() as appropriate.
	 *
	 * @param array $data Data
	 * @param int $class_post_id Optional; post ID of the class this item belongs to. Defaults to zero (not a method).
	 * @param bool $import_internal Optional; defaults to false. If true, functions or classes marked @internal will be imported.
	 * @param array $arg_overrides Optional; array of parameters that override the defaults passed to wp_update_post().
	 * @return bool|int Post ID of this item, false if any failure.
	 */
	public function import_item( array $data, $class_post_id = 0, $import_internal = false, array $arg_overrides = array() ) {
		global $wpdb;

		// Don't import items marked @internal unless explicitly requested. See https://github.com/rmccue/WP-Parser/issues/16
		if ( ! $import_internal && wp_list_filter( $data['doc']['tags'], array( 'name' => 'internal' ) ) ) {

			if ( $post_data['post_type'] === $this->post_type_class )
				WP_CLI::line( sprintf( "\tSkipped importing @internal class \"%1\$s\"", $data['name'] ) );
			elseif ( $class_post_id )
				WP_CLI::line( sprintf( "\t\tSkipped importing @internal method \"%1\$s\"", $data['name'] ) );
			else
				WP_CLI::line( sprintf( "\tSkipped importing @internal function \"%1\$s\"", $data['name'] ) );

			return false;
		}

		$is_new_post = true;
		$slug        = sanitize_title( $data['name'] );
		$post_data   = wp_parse_args( $arg_overrides, array(
			'post_content' => $data['doc']['long_description'],
			'post_excerpt' => $data['doc']['description'],
			'post_name'    => $slug,
			'post_parent'  => (int) $class_post_id,
			'post_status'  => 'publish',
			'post_title'   => $data['name'],
			'post_type'    => $this->post_type_function,
		) );

		// Look for an existing post for this item
		$existing_post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s AND post_parent = %d LIMIT 1", $slug, $post_data['post_type'], (int) $class_post_id ) );

		// Insert/update the item post
		if ( ! empty( $existing_post_id ) ) {
			$is_new_post     = false;
			$post_data['ID'] = (int) $existing_post_id;
			$ID              = wp_update_post( $post_data, true );

		} else {
			$ID = wp_insert_post( $post_data, true );
		}

		if ( ! $ID || is_wp_error( $ID ) ) {

			if ( $post_data['post_type'] === $this->post_type_class )
				$this->errors[] = sprintf( "\tProblem inserting/updating post for class \"%1\$s\"", $data['name'], $ID->get_error_message() );
			elseif ( $class_post_id )
				$this->errors[] = sprintf( "\t\tProblem inserting/updating post for method \"%1\$s\"", $data['name'], $ID->get_error_message() );
			else
				$this->errors[] = sprintf( "\tProblem inserting/updating post for function \"%1\$s\"", $data['name'], $ID->get_error_message() );

			return false;
		}

		// If the item has @since markup, assign the taxonomy
		$since_version = wp_list_filter( $data['doc']['tags'], array( 'name' => 'since' ) );
		if ( ! empty( $since_version ) ) {

			$since_version = array_shift( $since_version );
			$since_version = $since_version['content'];
			$since_term    = term_exists( $since_version, $this->taxonomy_since_version );

			if ( ! $since_term )
				$since_term = wp_insert_term( $since_version, $this->taxonomy_since_version );

			// Assign the tax item to the post
			if ( ! is_wp_error( $since_term ) )
				wp_set_object_terms( $ID, (int) $since_term['term_id'], $this->taxonomy_since_version );
			else
				WP_CLI::warning( "\tCannot set @since term: " . $since_term->get_error_message() );
		}


		// Set other taxonomy and post meta to use in the theme templates
		wp_set_object_terms( $ID, $this->file_meta['term_id'], $this->taxonomy_file );
		update_post_meta( $ID, '_wpapi_args',     $data['arguments'] );
		update_post_meta( $ID, '_wpapi_line_num', $data['line'] );
		update_post_meta( $ID, '_wpapi_tags',     $data['doc']['tags'] );

		// Everything worked! Woo hoo!
		if ( $is_new_post ) {
			if ( $post_data['post_type'] === $this->post_type_class )
				WP_CLI::line( sprintf( "\tImported class \"%1\$s\"", $data['name'] ) );
			elseif ( $class_post_id )
				WP_CLI::line( sprintf( "\t\tImported method \"%1\$s\"", $data['name'] ) );
			else
				WP_CLI::line( sprintf( "\tImported function \"%1\$s\"", $data['name'] ) );

		} else {
			if ( $post_data['post_type'] === $this->post_type_class )
				WP_CLI::line( sprintf( "\tUpdated class \"%1\$s\"", $data['name'] ) );
			elseif ( $class_post_id )
				WP_CLI::line( sprintf( "\t\tUpdated method \"%1\$s\"", $data['name'] ) );
			else
				WP_CLI::line( sprintf( "\tUpdated function \"%1\$s\"", $data['name'] ) );
		}

		return $ID;
	}
}