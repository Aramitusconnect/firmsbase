try {
  $resp = app('router')->dispatch(
    Illuminate\Http\Request::create('https://app.staging.firmsvault.com/register','POST',[
      'firm_name'=>'T','first_name'=>'A','last_name'=>'B','email'=>'a@firmsbase-staging.internal']));
  echo 'status='.$resp->getStatusCode()."\n";
} catch (\Throwable $e) {
  echo get_class($e).': '.$e->getMessage()."\n";
  foreach (array_slice($e->getTrace(),0,8) as $t) {
    echo '  at '.basename($t['file'] ?? '?').':'.($t['line'] ?? '?').' '.($t['class'] ?? '').($t['type'] ?? '').($t['function'] ?? '')."\n";
  }
}
