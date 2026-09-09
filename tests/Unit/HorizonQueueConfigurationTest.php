<?php

it('supervises the sync queue for tax and integration jobs', function () {
    $queues = config('horizon.defaults.supervisor-1.queue', []);

    expect($queues)
        ->toBeArray()
        ->toContain('sync')
        ->toContain('default');
});
