<?php
/**
 * Media Controller
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Media_Controller {

    /**
     * Handle media uploads
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function upload_media( $request ) {
        // Get files from the request
        $files = $request->get_file_params();

        if ( empty( $files ) ) {
            return new WP_Error(
                'no_files',
                'No files were uploaded.',
                [ 'status' => 400 ]
            );
        }

        // Include core WordPress media libraries required for processing uploads
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $uploaded_images = [];

        // Loop through the uploaded files
        foreach ( $files as $file_key => $file_array ) {
            
            // If the client sends an array of files under a single key (e.g. images[])
            if ( is_array( $file_array['name'] ) ) {
                $count = count( $file_array['name'] );
                for ( $i = 0; $i < $count; $i++ ) {
                    $single_file = [
                        'name'     => $file_array['name'][$i],
                        'type'     => $file_array['type'][$i],
                        'tmp_name' => $file_array['tmp_name'][$i],
                        'error'    => $file_array['error'][$i],
                        'size'     => $file_array['size'][$i],
                    ];
                    $attachment_id = $this->process_single_upload( $single_file );
                    
                    if ( ! is_wp_error( $attachment_id ) ) {
                        $uploaded_images[] = $this->format_image_response( $attachment_id );
                    }
                }
            } else {
                // Single file upload per key
                $attachment_id = $this->process_single_upload( $file_array );
                
                if ( ! is_wp_error( $attachment_id ) ) {
                    $uploaded_images[] = $this->format_image_response( $attachment_id );
                }
            }
        }

        if ( empty( $uploaded_images ) ) {
            return new WP_Error( 'upload_failed', 'Failed to process any uploaded images.', [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'images' => $uploaded_images
        ] );
    }

    /**
     * Process a single file upload using WP functions
     */
    private function process_single_upload( $file ) {
        // Use wp_handle_sideload to process the file array securely
        $overrides = [ 'test_form' => false ];
        $movefile  = wp_handle_upload( $file, $overrides );

        if ( isset( $movefile['error'] ) ) {
            return new WP_Error( 'upload_error', $movefile['error'] );
        }

        // Prepare attachment data
        $attachment_data = [
            'guid'           => $movefile['url'],
            'post_mime_type' => $movefile['type'],
            'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $movefile['file'] ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => get_current_user_id() ?: 1, // Fallback if no user context
        ];

        // Insert the attachment into the media library
        $attachment_id = wp_insert_attachment( $attachment_data, $movefile['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        // Generate the metadata (creates thumbnails, sizes, etc.)
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $movefile['file'] );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        return $attachment_id;
    }

    /**
     * Format the attachment ID into the exact requested JSON schema
     */
    private function format_image_response( $attachment_id ) {
        $attachment = get_post( $attachment_id );
        $meta       = wp_get_attachment_metadata( $attachment_id );
        $file_path  = get_attached_file( $attachment_id );
        
        $author = get_userdata( $attachment->post_author );

        // Build the sizes array
        $sizes = [];
        if ( ! empty( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size_name => $size_data ) {
                
                // Calculate orientation
                $orientation = 'square';
                if ( $size_data['width'] > $size_data['height'] ) {
                    $orientation = 'landscape';
                } elseif ( $size_data['width'] < $size_data['height'] ) {
                    $orientation = 'portrait';
                }

                $sizes[ $size_name ] = [
                    'height'      => $size_data['height'],
                    'width'       => $size_data['width'],
                    'url'         => wp_get_attachment_image_url( $attachment_id, $size_name ),
                    'orientation' => $orientation
                ];
            }
        }

        return [
            'id'          => (string) $attachment_id,
            'filename'    => wp_basename( $file_path ),
            'url'         => wp_get_attachment_url( $attachment_id ),
            'sizes'       => (object) $sizes, // Cast to object so empty arrays become {} in JSON instead of []
            'height'      => $meta['height'] ?? 0,
            'width'       => $meta['width'] ?? 0,
            'filesize'    => file_exists( $file_path ) ? filesize( $file_path ) : 0,
            'mime'        => get_post_mime_type( $attachment_id ),
            'type'        => 'image',
            'thumb'       => null,
            'author'      => (string) $attachment->post_author,
            'author_name' => $author ? $author->display_name : '',
            'date'        => get_post_time( 'c', true, $attachment_id ) // Returns ISO 8601 string
        ];
    }
}