<?php
/**
 * MCP tool definitions and execution.
 * Uses Tutor LMS Pro REST API authenticated with the admin-configured API key.
 *
 * @package TutorLMS_MCP
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TLMS_MCP_Tools {

    // ══════════════════════════════════════════════════════════════════════════
    //  TOOL REGISTRY
    // ══════════════════════════════════════════════════════════════════════════

    public static function list_tools(): array {
        return [
            self::schema( 'tutor_list_courses', 'List all courses.',
                [ 'search' => [ 'type' => 'string' ], 'page' => [ 'type' => 'integer' ], 'per_page' => [ 'type' => 'integer' ] ], [] ),
            self::schema( 'tutor_get_course', 'Get full details of a course.',
                [ 'course_id' => [ 'type' => 'integer' ] ], [ 'course_id' ] ),
            self::schema( 'tutor_create_course', 'Create a new course.',
                [ 'post_title' => [ 'type' => 'string' ], 'post_content' => [ 'type' => 'string' ],
                  'post_status' => [ 'type' => 'string', 'enum' => [ 'draft','publish','private','pending' ] ],
                  'price' => [ 'type' => 'string' ], 'course_level' => [ 'type' => 'string' ] ],
                [ 'post_title' ] ),
            self::schema( 'tutor_update_course', 'Update a course.',
                [ 'course_id' => [ 'type' => 'integer' ], 'post_title' => [ 'type' => 'string' ],
                  'post_content' => [ 'type' => 'string' ], 'post_status' => [ 'type' => 'string' ],
                  'price' => [ 'type' => 'string' ] ], [ 'course_id' ] ),
            self::schema( 'tutor_delete_course', 'Delete or trash a course.',
                [ 'course_id' => [ 'type' => 'integer' ], 'force' => [ 'type' => 'boolean' ] ], [ 'course_id' ] ),
            self::schema( 'tutor_get_topics', 'Get all topics for a course.',
                [ 'course_id' => [ 'type' => 'integer' ] ], [ 'course_id' ] ),
            self::schema( 'tutor_create_topic', 'Create a topic inside a course.',
                [ 'course_id' => [ 'type' => 'integer' ], 'topic_title' => [ 'type' => 'string' ],
                  'topic_summary' => [ 'type' => 'string' ] ], [ 'course_id', 'topic_title' ] ),
            self::schema( 'tutor_update_topic', 'Update a topic.',
                [ 'topic_id' => [ 'type' => 'integer' ], 'topic_title' => [ 'type' => 'string' ],
                  'topic_summary' => [ 'type' => 'string' ] ], [ 'topic_id' ] ),
            self::schema( 'tutor_delete_topic', 'Delete a topic.',
                [ 'topic_id' => [ 'type' => 'integer' ] ], [ 'topic_id' ] ),
            self::schema( 'tutor_get_lesson', 'Get lesson details.',
                [ 'lesson_id' => [ 'type' => 'integer' ] ], [ 'lesson_id' ] ),
            self::schema( 'tutor_create_lesson', 'Create a lesson inside a topic.',
                [ 'topic_id'          => [ 'type' => 'integer' ],
                  'lesson_title'      => [ 'type' => 'string' ],
                  'lesson_content'    => [ 'type' => 'string' ],
                  'video_source_type' => [ 'type' => 'string', 'enum' => [ 'youtube', 'vimeo', 'external_url', 'html5', 'embedded', 'shortcode' ] ],
                  'video_source'      => [ 'type' => 'string' ],
                  'video_hours'       => [ 'type' => 'string' ],
                  'video_minutes'     => [ 'type' => 'string' ],
                  'video_seconds'     => [ 'type' => 'string' ],
                  'thumbnail_id'      => [ 'type' => 'integer' ],
                  'preview'           => [ 'type' => 'boolean' ] ],
                [ 'topic_id', 'lesson_title' ] ),
            self::schema( 'tutor_update_lesson', 'Update a lesson.',
                [ 'lesson_id'         => [ 'type' => 'integer' ],
                  'lesson_title'      => [ 'type' => 'string' ],
                  'lesson_content'    => [ 'type' => 'string' ],
                  'video_source_type' => [ 'type' => 'string', 'enum' => [ 'youtube', 'vimeo', 'external_url', 'html5', 'embedded', 'shortcode' ] ],
                  'video_source'      => [ 'type' => 'string' ],
                  'video_hours'       => [ 'type' => 'string' ],
                  'video_minutes'     => [ 'type' => 'string' ],
                  'video_seconds'     => [ 'type' => 'string' ],
                  'thumbnail_id'      => [ 'type' => 'integer' ],
                  'preview'           => [ 'type' => 'boolean' ] ], [ 'lesson_id' ] ),
            self::schema( 'tutor_delete_lesson', 'Delete a lesson.',
                [ 'lesson_id' => [ 'type' => 'integer' ] ], [ 'lesson_id' ] ),
            self::schema( 'tutor_get_quiz', 'Get quiz details including questions.',
                [ 'quiz_id' => [ 'type' => 'integer' ] ], [ 'quiz_id' ] ),
            self::schema( 'tutor_create_quiz', 'Create a quiz inside a topic.',
                [ 'topic_id'         => [ 'type' => 'integer' ],
                  'quiz_title'       => [ 'type' => 'string' ],
                  'quiz_description' => [ 'type' => 'string' ],
                  'passing_grade'    => [ 'type' => 'integer' ],
                  'time_limit_value' => [ 'type' => 'integer' ],
                  'time_limit_type'  => [ 'type' => 'string', 'enum' => [ 'seconds', 'minutes', 'hours', 'days', 'weeks' ] ],
                  'feedback_mode'    => [ 'type' => 'string', 'enum' => [ 'default', 'reveal', 'retry' ] ],
                  'attempts_allowed' => [ 'type' => 'integer' ] ], [ 'topic_id', 'quiz_title' ] ),
            self::schema( 'tutor_add_quiz_question', 'Add a question to a quiz.',
                [ 'quiz_id' => [ 'type' => 'integer' ], 'question_title' => [ 'type' => 'string' ],
                  'question_type' => [ 'type' => 'string' ], 'question_mark' => [ 'type' => 'integer' ],
                  'answers' => [ 'type' => 'array' ] ], [ 'quiz_id', 'question_title', 'question_type' ] ),
            self::schema( 'tutor_delete_quiz', 'Delete a quiz.',
                [ 'quiz_id' => [ 'type' => 'integer' ] ], [ 'quiz_id' ] ),
            self::schema( 'tutor_create_assignment', 'Create an assignment inside a topic.',
                [ 'topic_id' => [ 'type' => 'integer' ], 'assignment_title' => [ 'type' => 'string' ],
                  'assignment_content' => [ 'type' => 'string' ], 'total_mark' => [ 'type' => 'integer' ],
                  'pass_mark' => [ 'type' => 'integer' ] ], [ 'topic_id', 'assignment_title' ] ),
            self::schema( 'tutor_get_assignment', 'Get assignment details.',
                [ 'assignment_id' => [ 'type' => 'integer' ] ], [ 'assignment_id' ] ),
            self::schema( 'tutor_delete_assignment', 'Delete an assignment.',
                [ 'assignment_id' => [ 'type' => 'integer' ] ], [ 'assignment_id' ] ),
            self::schema( 'tutor_list_enrollments', 'List enrollments.',
                [ 'course_id' => [ 'type' => 'integer' ], 'student_id' => [ 'type' => 'integer' ],
                  'status' => [ 'type' => 'string' ], 'page' => [ 'type' => 'integer' ],
                  'per_page' => [ 'type' => 'integer' ] ], [] ),
            self::schema( 'tutor_enroll_student', 'Enroll a student in a course.',
                [ 'course_id' => [ 'type' => 'integer' ], 'student_id' => [ 'type' => 'integer' ] ],
                [ 'course_id', 'student_id' ] ),
            self::schema( 'tutor_update_enrollment', 'Update enrollment status.',
                [ 'enrollment_id' => [ 'type' => 'integer' ], 'status' => [ 'type' => 'string' ] ],
                [ 'enrollment_id', 'status' ] ),
            self::schema( 'tutor_list_qna', 'List Q&A questions.',
                [ 'course_id' => [ 'type' => 'integer' ], 'limit' => [ 'type' => 'integer' ],
                  'offset' => [ 'type' => 'integer' ] ], [] ),
            self::schema( 'tutor_answer_qna', 'Answer a Q&A question.',
                [ 'question_id' => [ 'type' => 'integer' ], 'answer' => [ 'type' => 'string' ] ],
                [ 'question_id', 'answer' ] ),
            self::schema( 'tutor_list_reviews', 'List course reviews.',
                [ 'course_id' => [ 'type' => 'integer' ], 'page' => [ 'type' => 'integer' ] ], [] ),
            self::schema( 'tutor_delete_review', 'Delete a review.',
                [ 'course_id' => [ 'type' => 'integer' ], 'user_id' => [ 'type' => 'integer' ] ],
                [ 'course_id', 'user_id' ] ),
            self::schema( 'tutor_list_announcements', 'List announcements for a course.',
                [ 'course_id' => [ 'type' => 'integer' ] ], [ 'course_id' ] ),
            self::schema( 'tutor_create_announcement', 'Create a course announcement.',
                [ 'course_id' => [ 'type' => 'integer' ], 'announcement_title' => [ 'type' => 'string' ],
                  'announcement_summary' => [ 'type' => 'string' ] ], [ 'course_id', 'announcement_title' ] ),
            self::schema( 'tutor_delete_announcement', 'Delete a course announcement.',
                [ 'announcement_id' => [ 'type' => 'integer' ] ], [ 'announcement_id' ] ),
            self::schema( 'tutor_list_students', 'List enrolled students for a course.',
                [ 'course_id' => [ 'type' => 'integer' ], 'page' => [ 'type' => 'integer' ],
                  'per_page' => [ 'type' => 'integer' ] ], [ 'course_id' ] ),
            self::schema( 'tutor_get_student_profile', 'Get a student profile.',
                [ 'user_id' => [ 'type' => 'integer' ] ], [ 'user_id' ] ),
            self::schema( 'tutor_get_course_content', 'Get full curriculum: topics, lessons, quizzes, assignments.',
                [ 'course_id' => [ 'type' => 'integer' ] ], [ 'course_id' ] ),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  DISPATCHER
    // ══════════════════════════════════════════════════════════════════════════

    public static function call_tool( string $name, array $args ): string {
        switch ( $name ) {
            case 'tutor_list_courses':        return self::list_courses( $args );
            case 'tutor_get_course':          return self::get_course( $args );
            case 'tutor_create_course':       return self::create_course( $args );
            case 'tutor_update_course':       return self::update_course( $args );
            case 'tutor_delete_course':       return self::delete_course( $args );
            case 'tutor_get_topics':          return self::get_topics( $args );
            case 'tutor_create_topic':        return self::create_topic( $args );
            case 'tutor_update_topic':        return self::update_topic( $args );
            case 'tutor_delete_topic':        return self::delete_topic( $args );
            case 'tutor_get_lesson':          return self::get_lesson( $args );
            case 'tutor_create_lesson':       return self::create_lesson( $args );
            case 'tutor_update_lesson':       return self::update_lesson( $args );
            case 'tutor_delete_lesson':       return self::delete_lesson( $args );
            case 'tutor_get_quiz':            return self::get_quiz( $args );
            case 'tutor_create_quiz':         return self::create_quiz( $args );
            case 'tutor_add_quiz_question':   return self::add_quiz_question( $args );
            case 'tutor_delete_quiz':         return self::delete_quiz( $args );
            case 'tutor_create_assignment':   return self::create_assignment( $args );
            case 'tutor_get_assignment':      return self::get_assignment( $args );
            case 'tutor_delete_assignment':   return self::delete_assignment( $args );
            case 'tutor_list_enrollments':    return self::list_enrollments( $args );
            case 'tutor_enroll_student':      return self::enroll_student( $args );
            case 'tutor_update_enrollment':   return self::update_enrollment( $args );
            case 'tutor_list_qna':            return self::list_qna( $args );
            case 'tutor_answer_qna':          return self::answer_qna( $args );
            case 'tutor_list_reviews':        return self::list_reviews( $args );
            case 'tutor_delete_review':       return self::delete_review( $args );
            case 'tutor_list_announcements':  return self::list_announcements( $args );
            case 'tutor_create_announcement': return self::create_announcement( $args );
            case 'tutor_delete_announcement': return self::delete_announcement( $args );
            case 'tutor_list_students':       return self::list_students( $args );
            case 'tutor_get_student_profile': return self::get_student_profile( $args );
            case 'tutor_get_course_content':  return self::get_course_content( $args );
            default: throw new \Exception( "Unknown tool: {$name}" );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  TUTOR PRO REST API — HTTP calls with Basic auth (api_key:secret_key)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Make an authenticated request to Tutor LMS Pro REST API.
     * Uses the api_key:secret_key stored in WP options, sent as HTTP Basic auth.
     */
    private static function tutor( string $method, string $path, array $params = [] ): array {
        $auth = TLMS_MCP_Admin::get_auth_header();
        if ( empty( $auth ) ) {
            throw new \Exception( 'Tutor LMS API key not configured. Go to Tutor MCP → Settings and enter your API keys.' );
        }

        $base_url = rest_url( 'tutor/v1' . $path );
        $method   = strtoupper( $method );
        $args     = [
            'method'  => $method,
            'headers' => [
                'Authorization' => $auth,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ( in_array( $method, [ 'GET', 'DELETE' ], true ) && ! empty( $params ) ) {
            $base_url = add_query_arg( $params, $base_url );
        } elseif ( ! empty( $params ) ) {
            $args['body'] = wp_json_encode( $params );
        }

        $response = wp_remote_request( $base_url, $args );

        if ( is_wp_error( $response ) ) {
            throw new \Exception( 'HTTP error: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );
        $data   = json_decode( $body, true );

        if ( $status >= 400 ) {
            $msg = $data['message'] ?? $body;
            throw new \Exception( "Tutor API error {$status}: {$msg}" );
        }

        return $data ?? [];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  COURSES
    // ══════════════════════════════════════════════════════════════════════════

    private static function list_courses( array $a ): string {
        $p = array_filter( [
            'paged'    => $a['page']     ?? 1,
            'per_page' => $a['per_page'] ?? 10,
            'search'   => $a['search']   ?? null,
        ] );
        return self::ok( self::tutor( 'GET', '/courses', $p ) );
    }

    private static function get_course( array $a ): string {
        self::require_int( $a, 'course_id' );
        return self::ok( self::tutor( 'GET', '/courses/' . intval( $a['course_id'] ) ) );
    }

    private static function create_course( array $a ): string {
        self::require_string( $a, 'post_title' );
        return self::ok( self::tutor( 'POST', '/courses', array_filter( [
            'post_author'  => get_current_user_id(),
            'post_title'   => sanitize_text_field( $a['post_title'] ),
            'post_content' => $a['post_content'] ?? '',
            'post_status'  => $a['post_status']  ?? 'draft',
            'price'        => $a['price']        ?? null,
            'course_level' => $a['course_level'] ?? null,
        ] ) ) );
    }

    private static function update_course( array $a ): string {
        self::require_int( $a, 'course_id' );
        $id = intval( $a['course_id'] );
        return self::ok( self::tutor( 'POST', "/courses/{$id}", array_filter( [
            'post_title'   => $a['post_title']   ?? null,
            'post_content' => $a['post_content'] ?? null,
            'post_status'  => $a['post_status']  ?? null,
            'price'        => $a['price']        ?? null,
        ] ) ) );
    }

    private static function delete_course( array $a ): string {
        self::require_int( $a, 'course_id' );
        $id    = intval( $a['course_id'] );
        $force = ! empty( $a['force'] ) ? 'true' : 'false';
        return self::ok( self::tutor( 'DELETE', "/courses/{$id}", [ 'force' => $force ] ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  TOPICS
    // ══════════════════════════════════════════════════════════════════════════

    private static function get_topics( array $a ): string {
        self::require_int( $a, 'course_id' );
        return self::ok( self::tutor( 'GET', '/topics', [ 'course_id' => intval( $a['course_id'] ) ] ) );
    }

    private static function create_topic( array $a ): string {
        self::require_int( $a, 'course_id' ); self::require_string( $a, 'topic_title' );
        return self::ok( self::tutor( 'POST', '/topics', [
            'topic_course_id' => intval( $a['course_id'] ),
            'topic_title'     => sanitize_text_field( $a['topic_title'] ),
            'topic_summary'   => sanitize_text_field( $a['topic_summary'] ?? '' ),
            'topic_author'    => get_current_user_id(),
        ] ) );
    }

    private static function update_topic( array $a ): string {
        self::require_int( $a, 'topic_id' );
        return self::ok( self::tutor( 'POST', '/topics/' . intval( $a['topic_id'] ), array_filter( [
            'topic_title'   => $a['topic_title']   ?? null,
            'topic_summary' => $a['topic_summary'] ?? null,
        ] ) ) );
    }

    private static function delete_topic( array $a ): string {
        self::require_int( $a, 'topic_id' );
        return self::ok( self::tutor( 'DELETE', '/topics/' . intval( $a['topic_id'] ) ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  LESSONS
    // ══════════════════════════════════════════════════════════════════════════

    private static function get_lesson( array $a ): string {
        self::require_int( $a, 'lesson_id' );
        return self::ok( self::tutor( 'GET', '/lessons/' . intval( $a['lesson_id'] ) ) );
    }

    private static function create_lesson( array $a ): string {
        self::require_int( $a, 'topic_id' ); self::require_string( $a, 'lesson_title' );
        $body = [
            'topic_id'       => intval( $a['topic_id'] ),
            'lesson_title'   => sanitize_text_field( $a['lesson_title'] ),
            'lesson_content' => $a['lesson_content'] ?? '',
            'lesson_author'  => get_current_user_id(),
        ];
        if ( ! empty( $a['thumbnail_id'] ) ) {
            $body['thumbnail_id'] = intval( $a['thumbnail_id'] );
        }
        if ( isset( $a['preview'] ) ) {
            $body['preview'] = (bool) $a['preview'];
        }
        if ( ! empty( $a['video_source'] ) ) {
            $body['video'] = [
                'source_type' => sanitize_text_field( $a['video_source_type'] ?? 'youtube' ),
                'source'      => esc_url_raw( $a['video_source'] ),
                'runtime'     => [
                    'hours'   => str_pad( $a['video_hours']   ?? '0', 2, '0', STR_PAD_LEFT ),
                    'minutes' => str_pad( $a['video_minutes'] ?? '0', 2, '0', STR_PAD_LEFT ),
                    'seconds' => str_pad( $a['video_seconds'] ?? '0', 2, '0', STR_PAD_LEFT ),
                ],
            ];
        }
        return self::ok( self::tutor( 'POST', '/lessons', $body ) );
    }

    private static function update_lesson( array $a ): string {
        self::require_int( $a, 'lesson_id' );
        $body = array_filter( [
            'lesson_title'   => $a['lesson_title']   ?? null,
            'lesson_content' => $a['lesson_content'] ?? null,
            'thumbnail_id'   => ! empty( $a['thumbnail_id'] ) ? intval( $a['thumbnail_id'] ) : null,
        ] );
        if ( isset( $a['preview'] ) ) {
            $body['preview'] = (bool) $a['preview'];
        }
        if ( ! empty( $a['video_source'] ) ) {
            $body['video'] = [
                'source_type' => sanitize_text_field( $a['video_source_type'] ?? 'youtube' ),
                'source'      => esc_url_raw( $a['video_source'] ),
                'runtime'     => [
                    'hours'   => str_pad( $a['video_hours']   ?? '0', 2, '0', STR_PAD_LEFT ),
                    'minutes' => str_pad( $a['video_minutes'] ?? '0', 2, '0', STR_PAD_LEFT ),
                    'seconds' => str_pad( $a['video_seconds'] ?? '0', 2, '0', STR_PAD_LEFT ),
                ],
            ];
        }
        return self::ok( self::tutor( 'POST', '/lessons/' . intval( $a['lesson_id'] ), $body ) );
    }

    private static function delete_lesson( array $a ): string {
        self::require_int( $a, 'lesson_id' );
        return self::ok( self::tutor( 'DELETE', '/lessons/' . intval( $a['lesson_id'] ) ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  QUIZZES
    // ══════════════════════════════════════════════════════════════════════════

    private static function get_quiz( array $a ): string {
        self::require_int( $a, 'quiz_id' );
        return self::ok( self::tutor( 'GET', '/quiz/' . intval( $a['quiz_id'] ) ) );
    }

    private static function create_quiz( array $a ): string {
        self::require_int( $a, 'topic_id' ); self::require_string( $a, 'quiz_title' );
        $quiz_options = [
            'passing_grade'  => $a['passing_grade'] ?? 80,
            'feedback_mode'  => $a['feedback_mode'] ?? 'default',
            'attempts_allowed' => $a['attempts_allowed'] ?? 0,
        ];
        if ( ! empty( $a['time_limit_value'] ) ) {
            $quiz_options['time_limit'] = [
                'time_value' => intval( $a['time_limit_value'] ),
                'time_type'  => sanitize_text_field( $a['time_limit_type'] ?? 'minutes' ),
            ];
        }
        return self::ok( self::tutor( 'POST', '/quizzes', [
            'topic_id'         => intval( $a['topic_id'] ),
            'quiz_title'       => sanitize_text_field( $a['quiz_title'] ),
            'quiz_author'      => get_current_user_id(),
            'quiz_description' => $a['quiz_description'] ?? '',
            'quiz_options'     => $quiz_options,
        ] ) );
    }

    private static function add_quiz_question( array $a ): string {
        self::require_int( $a, 'quiz_id' ); self::require_string( $a, 'question_title' ); self::require_string( $a, 'question_type' );
        return self::ok( self::tutor( 'POST', '/quiz-questions', array_filter( [
            'quiz_id'        => intval( $a['quiz_id'] ),
            'question_title' => sanitize_text_field( $a['question_title'] ),
            'question_type'  => $a['question_type'],
            'question_mark'  => $a['question_mark'] ?? 1,
            'answers'        => $a['answers']       ?? null,
        ] ) ) );
    }

    private static function delete_quiz( array $a ): string {
        self::require_int( $a, 'quiz_id' );
        return self::ok( self::tutor( 'DELETE', '/quizzes/' . intval( $a['quiz_id'] ) ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ASSIGNMENTS
    // ══════════════════════════════════════════════════════════════════════════

    private static function create_assignment( array $a ): string {
        self::require_int( $a, 'topic_id' ); self::require_string( $a, 'assignment_title' );
        return self::ok( self::tutor( 'POST', '/assignments', [
            'topic_id'            => intval( $a['topic_id'] ),
            'assignment_title'    => sanitize_text_field( $a['assignment_title'] ),
            'assignment_author'   => get_current_user_id(),
            'assignment_content'  => $a['assignment_content'] ?? '',
            'assignment_options'  => [
                'time_duration' => [ 'value' => $a['time_duration_value'] ?? 1, 'unit' => $a['time_duration_unit'] ?? 'weeks' ],
                'total_mark'    => $a['total_mark'] ?? 10,
                'pass_mark'     => $a['pass_mark']  ?? 5,
            ],
        ] ) );
    }

    private static function get_assignment( array $a ): string {
        self::require_int( $a, 'assignment_id' );
        return self::ok( self::tutor( 'GET', '/course-assignment/' . intval( $a['assignment_id'] ) ) );
    }

    private static function delete_assignment( array $a ): string {
        self::require_int( $a, 'assignment_id' );
        return self::ok( self::tutor( 'DELETE', '/assignments/' . intval( $a['assignment_id'] ) ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ENROLLMENTS
    // ══════════════════════════════════════════════════════════════════════════

    private static function list_enrollments( array $a ): string {
        return self::ok( self::tutor( 'GET', '/enrollments', array_filter( [
            'course_id'  => $a['course_id']  ?? null,
            'student_id' => $a['student_id'] ?? null,
            'status'     => $a['status']     ?? null,
            'page'       => $a['page']       ?? 1,
            'per_page'   => $a['per_page']   ?? 20,
        ] ) ) );
    }

    private static function enroll_student( array $a ): string {
        self::require_int( $a, 'course_id' ); self::require_int( $a, 'student_id' );
        return self::ok( self::tutor( 'POST', '/enrollments', [
            'course_id' => intval( $a['course_id'] ),
            'user_id'   => intval( $a['student_id'] ),
        ] ) );
    }

    private static function update_enrollment( array $a ): string {
        self::require_int( $a, 'enrollment_id' ); self::require_string( $a, 'status' );
        return self::ok( self::tutor( 'PUT', '/enrollments/completed', [
            'enrollment_id' => intval( $a['enrollment_id'] ),
            'status'        => $a['status'],
        ] ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Q&A
    // ══════════════════════════════════════════════════════════════════════════

    private static function list_qna( array $a ): string {
        return self::ok( self::tutor( 'GET', '/qna', array_filter( [
            'course_id' => $a['course_id'] ?? null,
            'limit'     => $a['limit']     ?? 20,
            'offset'    => $a['offset']    ?? 0,
        ] ) ) );
    }

    private static function answer_qna( array $a ): string {
        self::require_int( $a, 'question_id' ); self::require_string( $a, 'answer' );
        return self::ok( self::tutor( 'POST', '/qna', [
            'question_id' => intval( $a['question_id'] ),
            'qna_text'    => sanitize_textarea_field( $a['answer'] ),
        ] ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  REVIEWS
    // ══════════════════════════════════════════════════════════════════════════

    private static function list_reviews( array $a ): string {
        return self::ok( self::tutor( 'GET', '/reviews', array_filter( [
            'course_id' => $a['course_id'] ?? null,
            'page'      => $a['page']      ?? 1,
            'per_page'  => $a['per_page']  ?? 20,
        ] ) ) );
    }

    private static function delete_review( array $a ): string {
        self::require_int( $a, 'course_id' ); self::require_int( $a, 'user_id' );
        $path = '/reviews/' . intval( $a['course_id'] ) . '?user_id=' . intval( $a['user_id'] );
        return self::ok( self::tutor( 'POST', $path ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ANNOUNCEMENTS
    // ══════════════════════════════════════════════════════════════════════════

    private static function list_announcements( array $a ): string {
        self::require_int( $a, 'course_id' );
        return self::ok( self::tutor( 'GET', '/course-announcement/' . intval( $a['course_id'] ) ) );
    }

    private static function create_announcement( array $a ): string {
        self::require_int( $a, 'course_id' ); self::require_string( $a, 'announcement_title' );
        return self::ok( self::tutor( 'POST', '/announcements', [
            'course_id'            => intval( $a['course_id'] ),
            'announcement_title'   => sanitize_text_field( $a['announcement_title'] ),
            'announcement_summary' => sanitize_textarea_field( $a['announcement_summary'] ?? '' ),
        ] ) );
    }

    private static function delete_announcement( array $a ): string {
        self::require_int( $a, 'announcement_id' );
        return self::ok( self::tutor( 'DELETE', '/announcements/' . intval( $a['announcement_id'] ) ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  STUDENTS
    // ══════════════════════════════════════════════════════════════════════════

    private static function list_students( array $a ): string {
        self::require_int( $a, 'course_id' );
        return self::ok( self::tutor( 'GET', '/enrollments', array_filter( [
            'course_id' => intval( $a['course_id'] ),
            'page'      => $a['page']     ?? 1,
            'per_page'  => $a['per_page'] ?? 20,
        ] ) ) );
    }

    private static function get_student_profile( array $a ): string {
        self::require_int( $a, 'user_id' );
        return self::ok( self::tutor( 'GET', '/profile/' . intval( $a['user_id'] ) ) );
    }

    private static function get_course_content( array $a ): string {
        self::require_int( $a, 'course_id' );
        return self::ok( self::tutor( 'GET', '/course-contents/' . intval( $a['course_id'] ) ) );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    private static function ok( $data ): string {
        return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    private static function schema( string $name, string $desc, array $props, array $required = [] ): array {
        $schema = [ 'type' => 'object', 'properties' => $props ];
        if ( $required ) $schema['required'] = $required;
        return [ 'name' => $name, 'description' => $desc, 'inputSchema' => $schema ];
    }

    private static function require_int( array $a, string $key ): void {
        if ( empty( $a[ $key ] ) || ! is_numeric( $a[ $key ] ) )
            throw new \Exception( "Missing required integer: {$key}" );
    }

    private static function require_string( array $a, string $key ): void {
        if ( ! isset( $a[ $key ] ) || trim( $a[ $key ] ) === '' )
            throw new \Exception( "Missing required string: {$key}" );
    }
}
