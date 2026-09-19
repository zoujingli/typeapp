<?php

declare(strict_types=1);

use Type\Core\Http\Message\Factory;

function messageExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function main(int $argc, array $argv): void
{
    $factory = new Factory();
    $stream = $factory->createStream('原生正文');
    messageExpect($stream->tell() === 0 && $stream->getSize() === 12, '工厂必须生成定位于开头的流');
    messageExpect($stream->read(6) === '原生' && $stream->getContents() === '正文', '流没有保留字节内容与当前位置');
    messageExpect((string) $stream === '原生正文', '字符串转换必须从流开头读取');
    $stream->rewind();
    messageExpect($stream->write('PSR') === 3 && (string) $stream === 'PSR生正文', '可写流内容错误');
    $resource = $stream->detach();
    messageExpect(is_resource($resource) && !$stream->isReadable() && !$stream->isWritable() && $stream->getSize() === null, '分离后流仍然可用');
    fclose($resource);
    $closed = false;
    try {
        $stream->read(1);
    } catch (RuntimeException $error) {
        $closed = true;
    }
    messageExpect($closed && (string) $stream === '', '分离流必须拒绝读取且字符串转换安全');
    $uri = $factory->createUri('HTTPS://Example.COM:443/a%2Fb?q=原生#片段');
    messageExpect($uri->getScheme() === 'https' && $uri->getHost() === 'example.com' && $uri->getPort() === null, 'URI 大小写或标准端口错误');
    messageExpect((string) $uri === 'https://example.com/a%2Fb?q=%E5%8E%9F%E7%94%9F#%E7%89%87%E6%AE%B5', 'URI 编码不正确或出现重复编码');
    $changedUri = $uri->withPath('new path')->withQuery('x=%2F&y=%')->withFragment('')->withPort(8443);
    messageExpect((string) $changedUri === 'https://example.com:8443/new%20path?x=%2F&y=%25' && $uri->getPath() === '/a%2Fb', 'URI 修改没有保留原对象');
    messageExpect((string) $factory->createUri()->withPath('//relative') === '/relative', '无 authority URI 路径错误');
    messageExpect((string) $factory->createUri('http://[::1]:8080/')->withUserInfo('甲', 'a:b') === 'http://%E7%94%B2:a:b@[::1]:8080/', 'URI 用户信息或 IPv6 错误');
    $invalidPort = false;
    try {
        $uri->withPort(65536);
    } catch (InvalidArgumentException $error) {
        $invalidPort = true;
    }
    messageExpect($invalidPort, 'URI 没有拒绝无效端口');
    $response = $factory->createResponse(201)->withHeader('X-Trace', ' one ')->withAddedHeader('x-trace', ['two']);
    $changed = $response->withHeader('X-Trace', 'three')->withStatus(404)->withBody($factory->createStream('新正文'));
    messageExpect($response->getHeader('X-TRACE') === ['one', 'two'] && $response->getHeaders()['X-Trace'] === ['one', 'two'], '消息头未忽略查询大小写或丢失原始名称');
    messageExpect($response->getStatusCode() === 201 && $response->getReasonPhrase() === 'Created' && (string) $response->getBody() === '', '响应修改污染原对象');
    messageExpect($changed->getStatusCode() === 404 && $changed->getReasonPhrase() === 'Not Found' && $changed->getHeaderLine('x-trace') === 'three', '响应状态或替换消息头失败');
    messageExpect(!$changed->withoutHeader('X-TRACE')->hasHeader('x-trace') && $changed->hasHeader('x-trace'), '移除消息头污染原对象');
    $injection = false;
    try {
        $response->withHeader('X-Test', "value\r\nSet-Cookie: secret");
    } catch (InvalidArgumentException $error) {
        $injection = true;
    }
    messageExpect($injection, '消息头没有拒绝响应拆分字符');
    $request = $factory->createRequest('GET', 'http://example.test:8080/p?q=1#fragment');
    messageExpect($request->getRequestTarget() === '/p?q=1' && $request->getHeaderLine('Host') === 'example.test:8080', '请求目标或 Host 构造错误');
    $next = $request->withUri($factory->createUri('https://other.test/next'));
    messageExpect($next->getHeaderLine('Host') === 'other.test' && $request->getUri()->getHost() === 'example.test', 'URI 修改没有更新 Host 或污染原请求');
    messageExpect($request->withUri($factory->createUri('https://other.test/'), true)->getHeaderLine('Host') === 'example.test:8080', 'preserveHost 没有保留 Host');
    messageExpect($factory->createRequest('OPTIONS', '')->withRequestTarget('*')->getRequestTarget() === '*', '显式请求目标错误');
    $server = $factory->createServerRequest('POST', '/submit?q=raw', ['REMOTE_ADDR' => '127.0.0.1']);
    $populated = $server->withQueryParams(['q' => 'parsed'])->withCookieParams(['sid' => 'one'])
        ->withParsedBody(['name' => '甲'])->withAttribute('identity', 'request-a')->withAttribute('nullable', null);
    messageExpect($server->getAttributes() === [] && $server->getParsedBody() === null && $server->getQueryParams() === [], '服务器请求修改污染原对象');
    messageExpect($populated->getServerParams()['REMOTE_ADDR'] === '127.0.0.1' && $populated->getCookieParams()['sid'] === 'one'
        && $populated->getParsedBody()['name'] === '甲' && $populated->getQueryParams()['q'] === 'parsed'
        && $populated->getUri()->getQuery() === 'q=raw', '服务器参数、解析正文或独立查询参数错误');
    messageExpect($populated->getAttribute('nullable', 'default') === null && $populated->withoutAttribute('identity')->getAttribute('identity', 'none') === 'none'
        && $populated->getAttribute('identity') === 'request-a', '请求属性不存在与 null 混淆或移除属性污染原对象');
    $invalidBody = false;
    try {
        $server->withParsedBody('not parsed');
    } catch (InvalidArgumentException $error) {
        $invalidBody = true;
    }
    messageExpect($invalidBody, '服务器请求没有拒绝无效解析正文');
    $upload = $factory->createUploadedFile($factory->createStream('上传正文'), null, UPLOAD_ERR_OK, '正文.txt', 'text/plain');
    messageExpect($upload->getSize() === 12 && $upload->getClientFilename() === '正文.txt' && $upload->getClientMediaType() === 'text/plain', '上传文件元数据错误');
    $withUpload = $server->withUploadedFiles(['form' => ['document' => $upload]]);
    messageExpect($server->getUploadedFiles() === [] && $withUpload->getUploadedFiles()['form']['document'] === $upload, '上传文件树没有保留不可变语义');
    $target = tempnam(sys_get_temp_dir(), 'type_psr_upload_');
    messageExpect($target !== false, '无法创建上传测试路径');
    try {
        $upload->getStream()->read(3);
        $upload->moveTo((string) $target);
        messageExpect(file_get_contents((string) $target) === '上传正文', '移动文件没有保留完整内容');
        $moved = false;
        try {
            $upload->getStream();
        } catch (RuntimeException $error) {
            $moved = true;
        }
        messageExpect($moved, '移动完成后仍然可以获取原上传流');
        $movedAgain = false;
        try {
            $upload->moveTo((string) $target);
        } catch (RuntimeException $error) {
            $movedAgain = true;
        }
        messageExpect($movedAgain, '同一上传文件允许重复移动');
    } finally {
        unlink((string) $target);
    }
    $failedUpload = $factory->createUploadedFile($factory->createStream(), 0, UPLOAD_ERR_PARTIAL);
    $partialRejected = false;
    try {
        $failedUpload->getStream();
    } catch (RuntimeException $error) {
        $partialRejected = true;
    }
    messageExpect($partialRejected, '失败上传暴露了可用文件流');
    $invalidMode = false;
    try {
        $factory->createStreamFromFile('php://temp', 'invalid');
    } catch (InvalidArgumentException $error) {
        $invalidMode = true;
    }
    messageExpect($invalidMode, '流工厂没有按 PSR-17 拒绝无效模式');
    $writeOnly = fopen('/dev/null', 'w');
    messageExpect(is_resource($writeOnly), '无法创建只写资源');
    $unreadable = false;
    try {
        $factory->createStreamFromResource($writeOnly);
    } catch (InvalidArgumentException $error) {
        $unreadable = true;
    }
    messageExpect($unreadable && is_resource($writeOnly), '流工厂接受了不可读资源或关闭了拒绝接管的资源');
    fclose($writeOnly);
    echo "PSR 消息原生行为验证通过。\n";
}
