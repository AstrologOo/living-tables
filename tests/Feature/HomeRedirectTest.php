<?php

it('redirects the home page to the API documentation', function () {
    $this->get('/')->assertRedirect('/docs');
});
