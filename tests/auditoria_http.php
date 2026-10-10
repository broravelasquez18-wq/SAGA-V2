<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/conexion.php';
if (!in_array(getenv('DB_HOST'), ['localhost','127.0.0.1','::1'], true)) exit('Solo local');
$db=conexion(); $db->select_db('saga_audit_20261004'); $db->set_charset('utf8mb4');
$results=[]; $sessions=[]; $tokens=[];
function req(string $role, string $path, ?array $data=null): array {
    global $sessions;
    if (!isset($sessions[$role])) { $sessions[$role]=curl_init(); curl_setopt($sessions[$role],CURLOPT_COOKIEFILE,''); }
    $c=$sessions[$role];
    curl_setopt_array($c,[CURLOPT_URL=>'http://127.0.0.1:8097/'.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>30]);
    if ($data!==null) {curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($data));}
    else curl_setopt($c,CURLOPT_HTTPGET,true);
    $raw=curl_exec($c);
    if($raw===false) throw new RuntimeException(curl_error($c));
    $header=substr($raw,0,curl_getinfo($c,CURLINFO_HEADER_SIZE));
    $body=substr($raw,curl_getinfo($c,CURLINFO_HEADER_SIZE));
    preg_match('/^Location:\s*(.*)$/mi',$header,$loc);
    return ['status'=>curl_getinfo($c,CURLINFO_HTTP_CODE),'location'=>trim($loc[1]??''),'body'=>$body];
}
function result(string $case, bool $ok, array $detail=[]): void {
    global $results;
    $results[]=['caso'=>$case,'ok'=>$ok,'detalle'=>$detail];
    echo ($ok?'OK ':'FALLO ').$case.' '.json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
}
function post(string $role,string $path,array $data): array {
    global $tokens; return req($role,'controllers/'.$path,$data+['csrf_token'=>$tokens[$role]]);
}
function val(string $sql) {global $db; return $db->query($sql)->fetch_row()[0]??null;}
function ocup(int $uid,int $amb,string $fi,string $ff,string $estado='ocupado',string $jornada='tarde'): int {
    global $db;
    $s=$db->prepare('INSERT INTO historial_ocupacion (usuario_id,ambiente_id,fecha_inicio,fecha_fin,estado,jornada) VALUES (?,?,?,?,?,?)');
    $s->bind_param('iissss',$uid,$amb,$fi,$ff,$estado,$jornada); $s->execute(); return $db->insert_id;
}
if (defined('SAGA_AUDIT_HELPERS_ONLY')) return;
foreach(['admin'=>1,'instructor'=>2,'celador'=>3,'vocero'=>4] as $role=>$id) {
    $home=req($role,'views/home.php'); preg_match('/name="csrf_token" value="([^"]+)"/',$home['body'],$m); $tokens[$role]=$m[1];
    $login=post($role,'loginController.php',['cedula'=>'9900000'.$id,'password'=>'AuditOnly!2026']);
    result('login '.$role,$login['status']===302 && str_contains($login['location'],'views/'.$role),['redirect'=>$login['location']]);
    foreach(glob(__DIR__.'/../views/'.$role.'/*.php') as $file) {
        if(str_starts_with(basename($file),'_')) continue;
        $path='views/'.$role.'/'.basename($file).'?sede_id=1&piso_id=1';
        $r=req($role,$path);
        preg_match('/(?:Fatal error|Warning|Deprecated|Parse error)[\s\S]{0,230}/',$r['body'],$err);
        result('pantalla '.$role.'/'.basename($file),$r['status']===200 && !$err,['status'=>$r['status'],'redirect'=>$r['location'],'error'=>strip_tags($err[0]??'')]);
    }
}
foreach(['instructor','celador','vocero'] as $role) {
    $r=req($role,'views/admin/index_sedes.php');
    result('bloqueo panel admin para '.$role,$r['status']===302);
}
$r=req('anon','views/admin/index_sedes.php');result('bloqueo anonimo',$r['status']===302);
$r=req('admin','controllers/CrearPiso.php',['sede_id'=>1,'nombre'=>'CSRF QA','descripcion'=>'QA']);result('CSRF crear piso',$r['status']===403);
$r=post('admin','GuardarSede.php',['nombre'=>'QA Creada','ciudad'=>'Pruebas','direccion'=>'QA','telefono'=>'','email'=>'','estado'=>'activa']);
result('crear sede',(int)val("SELECT COUNT(*) FROM sedes WHERE nombre='QA Creada'")===1,['redirect'=>$r['location']]);
$r=post('admin','CrearPiso.php',['sede_id'=>1,'nombre'=>'QA Creado','descripcion'=>'Prueba']);
result('crear piso',(int)val("SELECT COUNT(*) FROM pisos WHERE nombre='QA Creado'")===1);
$r=post('admin','CrearAmbiente.php',['sede_id'=>1,'nombre'=>'QA Creado','descripcion'=>'Prueba','piso_id'=>1,'estado'=>'disponible']);
result('crear ambiente',(int)val("SELECT COUNT(*) FROM ambientes WHERE nombre='QA Creado'")===1);
$r=post('admin','CrearFicha.php',['sede_id'=>1,'codigo_ficha'=>'QA3001','programa'=>'QA','ambiente_id'=>2]);
result('crear ficha',(int)val("SELECT COUNT(*) FROM fichas WHERE codigo_ficha='QA3001'")===1);
$r=post('admin','CrearVocero.php',['sede_id'=>1,'accion'=>'crear','nombre'=>'Vocero QA','apellido'=>'Prueba','cedula'=>'99000008','email'=>'qa8@example.test','contrasena'=>'AuditOnly!2026','programa_estudio'=>'QA','ficha_id'=>val("SELECT id FROM fichas WHERE codigo_ficha='QA3001'")]);
result('crear vocero y asociar ficha',(int)val("SELECT COUNT(*) FROM fichas f JOIN usuarios u ON u.id=f.vocero_id WHERE u.cedula='99000008'")===1,['redirect'=>$r['location'],'status'=>$r['status']]);
$future=date('Y-m-d',strtotime('next tuesday'));
$r=post('admin','RegistrarOcupacion.php',['sede_id'=>1,'ambiente_id'=>2,'usuario_id'=>2,'fecha'=>$future,'jornada'=>'tarde','observaciones'=>'QA']);
result('registrar reserva admin',(int)val("SELECT COUNT(*) FROM historial_ocupacion WHERE ambiente_id=2 AND DATE(fecha_inicio)='$future'")===1,['redirect'=>$r['location']]);
$r=post('admin','RegistrarOcupacion.php',['sede_id'=>1,'ambiente_id'=>2,'usuario_id'=>5,'fecha'=>$future,'jornada'=>'tarde','observaciones'=>'QA duplicada']);
result('rechazo reserva solapada',(int)val("SELECT COUNT(*) FROM historial_ocupacion WHERE ambiente_id=2 AND DATE(fecha_inicio)='$future'")===1);
$own=(int)val("SELECT id FROM historial_ocupacion WHERE ambiente_id=2 AND DATE(fecha_inicio)='$future' LIMIT 1");
$r=post('instructor','SolicitarCambioJornada.php',['id'=>$own,'jornada_nueva'=>'noche','motivo'=>'QA']);
$sc=(int)val("SELECT id FROM solicitudes_cambio_jornada WHERE ocupacion_id=$own LIMIT 1");
result('solicitar cambio jornada',$sc>0,['redirect'=>$r['location']]);
$r=post('admin','ResolverCambioJornada.php',['solicitud_id'=>$sc,'accion'=>'aprobar','respuesta'=>'QA']);
result('aprobar cambio jornada',val("SELECT jornada FROM historial_ocupacion WHERE id=$own")==='noche',['redirect'=>$r['location']]);
$r=post('instructor','SolicitarCancelacionOcupacion.php',['id'=>$own,'motivo'=>'QA']);
$sc=(int)val("SELECT id FROM solicitudes_cancelacion WHERE ocupacion_id=$own LIMIT 1");
result('solicitar cancelacion',$sc>0,['redirect'=>$r['location']]);
$r=post('admin','ResolverSolicitudCancelacion.php',['solicitud_id'=>$sc,'accion'=>'aprobar','respuesta'=>'QA']);
result('aprobar cancelacion',val("SELECT estado FROM historial_ocupacion WHERE id=$own")==='cancelado',['redirect'=>$r['location']]);
$r=post('vocero','SolicitarOcupacionVocero.php',['fecha'=>$future,'jornada'=>'tarde','motivo'=>'QA']);
$sol=(int)val('SELECT MAX(id) FROM solicitudes_ocupacion WHERE vocero_id=4');
result('solicitud vocero',$sol>0,['redirect'=>$r['location']]);
$r=post('celador','ResolverOcupacionSolicitud.php',['solicitud_id'=>$sol,'accion'=>'aprobar']);
$vocOcup=(int)val("SELECT ocupacion_id FROM solicitudes_ocupacion WHERE id=$sol");
result('celador aprueba ocupacion',$vocOcup>0,['redirect'=>$r['location']]);
$r=post('vocero','SolicitarFinalizacionVocero.php',['id'=>$vocOcup]);
result('vocero no finaliza reserva futura',str_contains($r['location'],'no_finalizable'));
$db->query("UPDATE historial_ocupacion SET fecha_inicio=DATE_SUB(NOW(), INTERVAL 1 HOUR), fecha_fin=DATE_ADD(NOW(), INTERVAL 2 HOUR) WHERE id=$vocOcup");
$r=post('vocero','SolicitarFinalizacionVocero.php',['id'=>$vocOcup]);
$sf=(int)val('SELECT MAX(id) FROM solicitudes_finalizacion WHERE vocero_id=4');
result('vocero solicita finalizacion',$sf>0,['redirect'=>$r['location']]);
if($sf) {
 $r=post('celador','ResolverFinalizacionCelador.php',['solicitud_id'=>$sf,'accion'=>'aprobar']);
 result('celador finaliza ocupacion',val("SELECT estado FROM historial_ocupacion WHERE id=$vocOcup")==='finalizado',['redirect'=>$r['location']]);
}
$r=req('vocero','controllers/ObtenerNotificaciones.php');
result('notificaciones JSON',$r['status']===200 && json_decode($r['body'],true)!==null);
foreach(['ReporteGeneral.php','ReporteOcupaciones.php','ReporteInstructores.php','ReporteCeladores.php'] as $endpoint) {
 foreach(['excel','pdf'] as $format) {
  $r=req('admin',"controllers/$endpoint?sede_id=1&formato=$format");
  result("exportar $endpoint $format",$r['status']===200 && str_starts_with($r['body'],$format==='pdf'?'%PDF':'PK'),['status'=>$r['status'],'bytes'=>strlen($r['body']),'firma'=>bin2hex(substr($r['body'],0,4))]);
 }
}
// Pruebas de permisos e integridad, exclusivamente sobre la base ficticia.
$r=req('vocero','controllers/ObtenerInstructor.php?id=5');
result('privacidad instructor otra sede',empty(json_decode($r['body'],true)['success']),['status'=>$r['status'],'expuesto'=>!empty(json_decode($r['body'],true)['success'])]);
$other=ocup(5,3,$future.' 06:00:00',$future.' 12:00:00');
$r=req('celador',"controllers/FinalizarOcupacion.php?id=$other&sede_id=2");
result('celador no finaliza otra sede sin CSRF',val("SELECT estado FROM historial_ocupacion WHERE id=$other")==='ocupado',['redirect'=>$r['location']]);
$r=post('celador','RegistrarOcupacion.php',['sede_id'=>2,'ambiente_id'=>3,'usuario_id'=>5,'fecha'=>date('Y-m-d',strtotime($future.' +1 day')),'jornada'=>'tarde','observaciones'=>'QA otra sede']);
result('celador no registra otra sede',(int)val("SELECT COUNT(*) FROM historial_ocupacion WHERE ambiente_id=3 AND observaciones='QA otra sede'")===0,['redirect'=>$r['location']]);
$fi=date('Y-m-d H:i:s',time()-3600); $ff=date('Y-m-d H:i:s',time()+3600);
$actual=ocup(2,2,$fi,$ff);
$r=post('instructor','FinalizarOcupacionInstructor.php',['id'=>$actual]);
$stored=val("SELECT fecha_fin FROM historial_ocupacion WHERE id=$actual");
result('finalizacion registra hora real',strtotime($stored)<=time()+60,['fin_guardado'=>$stored,'fin_programado'=>$ff]);
$id=ocup(2,2,$future.' 18:00:00',$future.' 22:00:00','finalizado','noche');
$db->query("INSERT INTO solicitudes_cancelacion (ocupacion_id,instructor_id,motivo) VALUES ($id,2,'QA solicitud obsoleta')");$sol=$db->insert_id;
$r=post('admin','ResolverSolicitudCancelacion.php',['solicitud_id'=>$sol,'accion'=>'aprobar','respuesta'=>'QA']);
result('cancelacion obsoleta no se aprueba',val("SELECT estado FROM solicitudes_cancelacion WHERE id=$sol")!=='aprobada',['ocupacion'=>val("SELECT estado FROM historial_ocupacion WHERE id=$id"),'solicitud'=>val("SELECT estado FROM solicitudes_cancelacion WHERE id=$sol")]);
$col='contrase'.chr(195).chr(177).'a';
$hash=val("SELECT `$col` FROM usuarios WHERE id=2");
$home=req('hash','views/home.php');preg_match('/name="csrf_token" value="([^"]+)"/',$home['body'],$m);
$r=req('hash','controllers/loginController.php',['csrf_token'=>$m[1],'cedula'=>'99000002','password'=>$hash]);
result('hash almacenado no sirve como password',!str_contains($r['location'],'dashboard_instructor'),['redirect'=>$r['location']]);
$db->query("UPDATE usuarios SET estado='inactivo' WHERE id=2");
$r=req('instructor','views/instructor/dashboard_instructor.php');
result('desactivar usuario revoca acceso existente',in_array($r['status'],[302,401],true),['status'=>$r['status']]);
$db->query("UPDATE usuarios SET estado='activo' WHERE id=2");
file_put_contents(__DIR__.'/auditoria_resultados.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
echo 'TOTAL '.count($results).' FALLOS '.count(array_filter($results,fn($r)=>!$r['ok'])).PHP_EOL;
