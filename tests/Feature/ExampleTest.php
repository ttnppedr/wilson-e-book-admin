<?php

test('root redirects to admin panel', function (): void {
    $this->get('/')->assertRedirect('/admin');
});
