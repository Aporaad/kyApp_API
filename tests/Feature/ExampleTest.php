<?php

test('the application returns a successful response', function () {
    // الخادم API فقط: الجذر مخطط بلا صفحة هبوط ويرد 204 (commit ced80e2)، فالتوقع
    // القديم 200 كان اختبار Laravel الافتراضي ولم يعد وصفاً للسلوك المقصود.
    // This server is API-only: the root intentionally answers 204 with no landing page.
    $response = $this->get('/');

    $response->assertStatus(204);
});
