<?php
/**
 * Exception thrown from the wp_redirect filter, so handlers that redirect
 * and exit can be tested.
 *
 * @package NotForTraining
 */

/**
 * Redirect signal for tests.
 */
class NotForTraining_Redirect_Exception extends Exception {}
