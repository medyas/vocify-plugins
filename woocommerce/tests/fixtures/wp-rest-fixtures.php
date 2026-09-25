<?php
/**
 * Minimal, test-only doubles for the WordPress REST classes
 * `Vocify_AI_Status_Receiver` depends on.
 *
 * Brain Monkey stubs WordPress *functions*, not classes — these three are
 * genuinely lightweight enough to fake outright rather than pull in a WP
 * bootstrap for. Each only implements what the receiver actually calls.
 *
 * @package VocifyAI
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string,string> Keyed exactly as the code under test calls get_header(). */
        private $headers;

        /** @var string */
        private $body;

        /**
         * @param array<string,string> $headers
         * @param string               $body
         */
        public function __construct($headers = array(), $body = '')
        {
            $this->headers = $headers;
            $this->body = $body;
        }

        /**
         * @param string $key
         * @return string|null
         */
        public function get_header($key)
        {
            return isset($this->headers[$key]) ? $this->headers[$key] : null;
        }

        /**
         * @return string
         */
        public function get_body()
        {
            return $this->body;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var mixed */
        public $data;

        /** @var int */
        public $status;

        /**
         * @param mixed $data
         * @param int   $status
         */
        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var string */
        public $code;

        /** @var string */
        public $message;

        /** @var array<string,mixed> */
        public $error_data;

        /**
         * @param string              $code
         * @param string              $message
         * @param array<string,mixed> $data
         */
        public function __construct($code = '', $message = '', $data = array())
        {
            $this->code = $code;
            $this->message = $message;
            $this->error_data = $data;
        }

        /**
         * @return int|null
         */
        public function get_error_data()
        {
            return isset($this->error_data['status']) ? $this->error_data['status'] : null;
        }
    }
}
