<?php
/**
 * Campaign Post Controller - Handles campaign endpoints
 */

use Growfund\Validation\Validator;
use Growfund\Sanitizer;
use Growfund\DTO\Comment\CommentFilterDTO;
use Growfund\DTO\Comment\CreateCommentDTO;
use Growfund\DTO\JsonResponseDTO;
use Growfund\Services\CommentService;
use Growfund\Views\Components\Comments\CommentCollection;
use Growfund\Views\Components\Comments\Comment;
use Growfund\Views\Components\Comments\CommentReply;

if ( ! defined( 'ABSPATH' ) ) exit;

class GFCM_Comment_Controller {

    /**
     * @var CommentService
     */
    protected $service;

    /**
     * Initialize the controller with CommentService.
     */
    public function __construct()
    {
        $this->service = new CommentService();
    }

    /**
     * Create a new Comment
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function create_comment( $request )
    {
        $data = [
            'post_id'    => $request->get_param('post_id'), // Make sure this matches your 'ids' validation key
            'content' => $request->get_param('content'),
            'parent_id' => $request->get_param('parent_id'),
            'comment_type' => $request->get_param('comment_type'),
        ];

        $validator = Validator::make($data, CreateCommentDTO::validation_rules());

        if ( $validator->is_failed() ) {
            $specific_errors = $validator->get_errors();
            
            // Convert the array of specific errors into a single readable string
            // e.g., "title: Required field, goal_amount: Must be numeric"
            $error_string = is_array( $specific_errors ) 
                ? implode( ', ', array_map(
                    function( $v, $k ) { return $k . ': ' . ( is_array( $v ) ? implode( ' ', $v ) : $v ); }, 
                    $specific_errors, 
                    array_keys( $specific_errors )
                )) 
                : 'Validation failed';

            return new WP_Error(
                'rest_invalid_param', // Standard WP REST API code for invalid parameters
                'Validation errors - ' . $error_string, 
                [
                    'status' => 422,
                    'details' => $specific_errors, // Next.js can read response.data.details to highlight specific inputs
                ]
            );
        }

        $sanitized_data = Sanitizer::make($data, CreateCommentDTO::sanitization_rules())->get_sanitized_data();

        $dto = CreateCommentDTO::from_array($sanitized_data);

        try {
            $comment_id = $this->service->store($dto);
            if ( ! $comment_id ) {
                return new WP_Error( 
                    'create_comment_failed', 
                    'Failed to create comment', 
                    [ 'status' => 404 ] 
                );
            }
        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'comment_create_error', 
                $e->getMessage(), // This will output "Failed to update campaign with ID..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => $comment_id,
            'message' => 'Comment created successfully.',
        ] );
    }

    /**
     * Get Comment by ID
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_comment_by_id( $request )
    {
        $comment_id = $request->get_param('id');

        try {
            $comment = $this->service->get_by_id($comment_id);
            if ( ! $comment_id ) {
                return new WP_Error( 
                    'comment_fetch_failed', 
                    'Failed to fetch comment by ID', 
                    [ 'status' => 404 ] 
                );
            }
        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'comment_create_error', 
                $e->getMessage(), // This will output "Failed to update campaign with ID..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }

        $comment_view = $comment->comment_parent ? new CommentReply() : new Comment();
        $comment_view->comment = $comment;

        return rest_ensure_response( [
            'success' => true,
            'data'    => $comment,
            'comment'    => $comment_view->comment,
            'message' => 'Comment fetched successfully.',
        ] );
    }

    /**
     * Get Paginated Comments
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_comments( $request )
    {
        $filter_dto = new CommentFilterDTO();
        $filter_dto->post_id = $request->get_param('post_id');
        $filter_dto->parent = $request->get_param('parent_id', 0);
        $filter_dto->page = $request->get_param('page', 1);
        $filter_dto->comment_type = $request->get_param('comment_type');

        try {
            $paginated = $this->service->paginated($filter_dto);
            if ( ! $paginated ) {
                return new WP_Error( 
                    'paginated_comments_fetch_failed', 
                    'Failed to fetch paginated comments', 
                    [ 'status' => 404 ] 
                );
            }
        } catch ( \Exception $e ) {
            // Capture the Exception and return a 400 Bad Request for ALL exceptions
            return new WP_Error(
                'paginated_comments_fetch_error', 
                $e->getMessage(), // This will output "Failed to update campaign with ID..."
                [ 'status' => 400 ] // <-- Assigning your single code right here
            );
        }

        $comment_collection = new CommentCollection();
        $comment_collection->comments = $paginated->results;
        $comment_collection->is_reply = $filter_dto->parent > 0;

        return rest_ensure_response( [
            'success' => true,
            'data'    => $paginated,
            'comments'    => $comment_collection,
            'message' => 'Paginated Comments fetched successfully.',
        ] );
    }
}