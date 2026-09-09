<?php

use App\Agents\Definitions\MyAgentAgent;
use App\Agents\Definitions\MyComplexAgentAgent;

test('placeholder agent definitions can provide metadata', function () {
    $agents = [
        new MyAgentAgent,
        new MyComplexAgentAgent,
    ];

    foreach ($agents as $agent) {
        $metadata = $agent->metadata();

        expect($metadata['name'])->not->toBeEmpty()
            ->and($metadata['description'])->not->toBeEmpty();
    }
});
