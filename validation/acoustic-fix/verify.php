<?php
$old=json_decode(file_get_contents('/home/lotus/CLionProjects/pcg729/validation/psampler-0.7.0/original-modules.json'),true);$new=get_loaded_extensions();sort($old);sort($new);if($old!==$new || !PHP_ZTS || PHP_VERSION!=='8.5.10')throw new RuntimeException('modules/version');
$pdo=new PDO('sqlite::memory:');assert($pdo->query('SELECT 42')->fetchColumn()===42);
$c=new bcg729Channel;assert(strlen($c->decode($c->encode(str_repeat("\0",160))))===160);
$o=new opusChannel(48000,1);assert(strlen($o->decode($o->encode(str_repeat("\0",1920))))===1920);
echo json_encode(['php'=>PHP_VERSION,'zts'=>PHP_ZTS,'psampler'=>phpversion('psampler'),'same_modules'=>true,'module_count'=>count($new),'modules'=>$new,'global_php_sha256'=>hash_file('sha256','/usr/local/bin/php'),'global_php_mtime'=>date('c',filemtime('/usr/local/bin/php'))],JSON_PRETTY_PRINT),"\n";
