<?php

use RWC\TwitterStream\RuleBuilder;

test("#7", function () {
    expect((string)RuleBuilder::create()->to(['@afelixdorn']))->toBe('to:@afelixdorn');
});

