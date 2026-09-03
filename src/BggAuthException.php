<?php
namespace App;

use Exception;

/**
 * Thrown by BggCollectionSync when a BGG API response indicates the cached
 * session cookies are no longer valid (expired/invalidated), as opposed to a
 * network error or an unrelated API failure. Callers use this to distinguish
 * "refresh the cached session and retry once" from "give up".
 */
class BggAuthException extends Exception {
}
