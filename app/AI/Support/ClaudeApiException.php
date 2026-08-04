<?php

namespace App\AI\Support;

use RuntimeException;

/** Anything that stops a Claude API call from producing a usable result — missing key, HTTP failure, or a response with no tool_use block. */
class ClaudeApiException extends RuntimeException {}
