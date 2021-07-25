<?php

namespace Bfg\EmbeddedCall\EmbeddedAttributes;

use \Attribute;

/**
 * Class Action
 * @package Bfg\EmbeddedCall\EmbeddedAttributes
 */
#[Attribute(Attribute::TARGET_METHOD|Attribute::TARGET_FUNCTION)]
class Action
{
    /**
     * Action constructor.
     * @param  string|null  $request
     * @param  string|null  $event
     * @param  string|null  $resource
     */
    public function __construct(
        public string|null $request = null,
        public string|null $event = null,
        public string|null $resource = null,
    ) {}
}
