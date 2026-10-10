<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SAGA_AUDIT_HELPERS_ONLY', true);
require __DIR__ . '/auditoria_http.php';
putenv('DB_NAME=saga_audit_20261004');
$column='contrase'.chr(195).chr(177).'a';
$db->query("UPDATE usuarios SET `$column`='AuditOnly!2026' WHERE id=5");
$home=req('legacy','views/home.php'); preg_match('/name="csrf_token" value="([^"]+)"/',$home['body'],$m);
$r=req('legacy','controllers/loginController.php',['cedula'=>'99000005','password'=>'AuditOnly!2026','csrf_token'=>$m[1]]);
result('primer login legacy conserva acceso y migra hash',str_contains($r['location'],'dashboard_instructor') && password_verify('AuditOnly!2026',val("SELECT `$column` FROM usuarios WHERE id=5")));
$db->query("UPDATE usuarios SET `$column`='AuditOnly!2026' WHERE id=7");
$db->query('ALTER TABLE solicitudes_cancelacion DROP COLUMN adjunto');
for ($run=1; $run<=2; $run++) {
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../migrate_auditoria.php').' --apply', $exit);
    result('migracion idempotente '.$run, $exit===0);
}
result('migracion conserva clave legacy con hash',password_verify('AuditOnly!2026',val("SELECT `$column` FROM usuarios WHERE id=7")));
result('migracion agrega adjunto', $db->query("SHOW COLUMNS FROM solicitudes_cancelacion LIKE 'adjunto'")->num_rows===1);
foreach (['admin'=>1,'instructor'=>2,'celador'=>3,'vocero'=>4] as $role=>$id) {
    $home=req($role,'views/home.php'); preg_match('/name="csrf_token" value="([^"]+)"/',$home['body'],$m); $tokens[$role]=$m[1];
    post($role,'loginController.php',['cedula'=>'9900000'.$id,'password'=>'AuditOnly!2026']);
}
$fi=date('Y-m-d H:i:s',time()-3600); $ff=date('Y-m-d H:i:s',time()+3600);
$legacy=ocup(4,1,date('Y-m-d H:i:s',time()+86400),date('Y-m-d H:i:s',time()+90000));
post('vocero','CancelarOcupacion.php',['id'=>$legacy]);
result('vocero no elimina reservas por endpoint antiguo',val("SELECT estado FROM historial_ocupacion WHERE id=$legacy")==='ocupado');
$other=ocup(5,3,$fi,$ff);
$r=post('celador','FinalizarOcupacion.php',['id'=>$other,'sede_id'=>2]);
result('POST con CSRF tampoco permite finalizar otra sede',$r['status']===403 && val("SELECT estado FROM historial_ocupacion WHERE id=$other")==='ocupado');
$mine=ocup(2,2,$fi,$ff);
$r=req('celador','controllers/FinalizarOcupacion.php',['id'=>$mine,'sede_id'=>1]);
result('finalizar POST sin CSRF rechazado',$r['status']===403 && val("SELECT estado FROM historial_ocupacion WHERE id=$mine")==='ocupado');
$r=post('celador','FinalizarOcupacion.php',['id'=>$mine,'sede_id'=>1]);
$end=val("SELECT fecha_fin FROM historial_ocupacion WHERE id=$mine");
result('celador finaliza propia sede con hora real',val("SELECT estado FROM historial_ocupacion WHERE id=$mine")==='finalizado' && abs(time()-strtotime($end))<60);
$count=(int)val('SELECT COUNT(*) FROM notificaciones');
post('celador','FinalizarOcupacion.php',['id'=>$mine,'sede_id'=>1]);
result('doble finalizacion no cambia hora ni avisa otra vez',val("SELECT fecha_fin FROM historial_ocupacion WHERE id=$mine")===$end && (int)val('SELECT COUNT(*) FROM notificaciones')===$count);
$future=ocup(2,2,date('Y-m-d H:i:s',time()+7200),date('Y-m-d H:i:s',time()+10800));
post('instructor','FinalizarOcupacionInstructor.php',['id'=>$future]);
result('instructor no finaliza antes de iniciar',val("SELECT estado FROM historial_ocupacion WHERE id=$future")==='ocupado');
foreach (['Instructor','Celador','Vocero'] as $kind) {
    $r=req('vocero','controllers/Obtener'.$kind.'.php?id=2');
    result('consulta administrativa '.$kind.' denegada al vocero',$r['status']===401);
}
$hash=password_hash('NuevaClaveQA2026',PASSWORD_DEFAULT);
$s=$db->prepare("UPDATE usuarios SET `$column`=? WHERE id=2");$s->bind_param('s',$hash);$s->execute();
$r=req('instructor','views/instructor/dashboard_instructor.php');
result('cambiar clave revoca sesion abierta',in_array($r['status'],[302,401],true));
$hash=password_hash('AuditOnly!2026',PASSWORD_DEFAULT);$s->execute();
foreach (['Instructor'=>201,'Celador'=>202,'Vocero'=>203] as $kind=>$suffix) {
    $cedula='99000'.$suffix;
    $password="Clave'QA\\2026";
    $data=['accion'=>'crear','sede_id'=>1,'cedula'=>$cedula,'nombre'=>'QA Hash','apellido'=>'Prueba',
        'email'=>$cedula.'@example.test','contrasena'=>$password,'tipo_contrato'=>'planta',
        'nivel_estudio'=>'tecnico','programa_estudio'=>'QA','ficha_id'=>'','telefono'=>''];
    post('admin','Crear'.$kind.'.php',$data);
    $stored=val("SELECT `$column` FROM usuarios WHERE cedula='$cedula'");
    result('crear '.$kind.' protege clave sin alterar caracteres',is_string($stored) && password_verify($password,$stored));
    $id=(int)val("SELECT id FROM usuarios WHERE cedula='$cedula'");
    $data['accion']='editar'; $data['id']=$id; $data['contrasena']='';
    post('admin','Crear'.$kind.'.php',$data);
    result('editar '.$kind.' sin clave conserva hash',val("SELECT `$column` FROM usuarios WHERE id=$id")===$stored);
}
require __DIR__.'/../config/calendario_habil.php';
foreach ([2025,2026,2027] as $year) {
    $expected=array_values(array_filter(FESTIVOS_CO,fn($d)=>str_starts_with($d,(string)$year)));
    // La lista anterior omitia por error el primero de mayo de 2027.
    if ($year===2027) $expected[]='2027-05-01';
    sort($expected);
    result('festivos dinamicos conservan calendario '.$year,festivos_colombia($year)===$expected);
}
result('festivos 2028 fijos y trasladados',es_dia_no_habil('2028-01-01') && es_dia_no_habil('2028-01-10') && !es_dia_no_habil('2028-01-11'));
file_put_contents(__DIR__.'/correcciones_resultados.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo 'TOTAL '.count($results).' FALLOS '.count(array_filter($results,fn($r)=>!$r['ok'])).PHP_EOL;
exit(count(array_filter($results,fn($r)=>!$r['ok'])) ? 1 : 0);
