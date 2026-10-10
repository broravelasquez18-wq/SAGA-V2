<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SAGA_AUDIT_HELPERS_ONLY',true);
require __DIR__.'/auditoria_http.php';
foreach(['admin'=>1,'instructor'=>2,'celador'=>3,'vocero'=>4] as $role=>$id) {
 $home=req($role,'views/home.php');preg_match('/name="csrf_token" value="([^"]+)"/',$home['body'],$m);$tokens[$role]=$m[1];
 post($role,'loginController.php',['cedula'=>'9900000'.$id,'password'=>'AuditOnly!2026']);
}
$future=date('Y-m-d',strtotime('next tuesday +14 days'));
$r=post('vocero','RegistrarOcupacionMultiple.php',['ambiente_id'=>3,'fecha'=>$future,'jornada'=>'tarde','observaciones'=>'QA bypass vocero']);
result('vocero no ocupa directamente sin aprobacion',(int)val("SELECT COUNT(*) FROM historial_ocupacion WHERE observaciones='QA bypass vocero'")===0,['redirect'=>$r['location']]);
$r=post('instructor','RegistrarOcupacionMultiple.php',['ambiente_id'=>3,'ficha_id'=>1,'fecha'=>$future,'jornada'=>'noche','observaciones'=>'QA instructor otra sede']);
result('instructor planta respeta sede',(int)val("SELECT COUNT(*) FROM historial_ocupacion WHERE observaciones='QA instructor otra sede'")===0,['redirect'=>$r['location']]);
$id=ocup(2,2,date('Y-m-d').' 21:00:00',date('Y-m-d').' 22:00:00','ocupado','noche');
$r=post('instructor','SolicitarCancelacionOcupacion.php',['id'=>$id,'motivo'=>'QA futura hoy']);
result('cancelacion solo hasta el dia anterior (politica confirmada)',str_contains($r['location'],'no_cancelable'),['redirect'=>$r['location']]);
$db->query("INSERT INTO ambientes (piso_id,nombre,estado) VALUES (1,'QA Vencimiento aislado','ocupado')");
$ambienteVencido=$db->insert_id;
$id=ocup(2,$ambienteVencido,date('Y-m-d H:i:s',time()-7200),date('Y-m-d H:i:s',time()-3600));
require __DIR__.'/../config/estados.php';expirar_ocupaciones($db);
result('cron cierra ocupacion vencida',val("SELECT estado FROM historial_ocupacion WHERE id=$id")==='finalizado');
result('cron actualiza estado almacenado del ambiente',val("SELECT estado FROM ambientes WHERE id=$ambienteVencido")==='disponible',['estado_ambiente'=>val("SELECT estado FROM ambientes WHERE id=$ambienteVencido")]);
require __DIR__.'/../config/calendario_habil.php';
result('festivo fijo 2028 bloqueado',es_dia_no_habil('2028-01-01'));
// Carga masiva de archivos sinteticos, sin correos ni datos reales.
$col='contrase'.chr(195).chr(177).'a';
$fixtures=[
 'Ambientes'=>[['piso','nombre','descripcion'],['Piso QA Uno','QA Importado','Prueba']],
 'Instructores'=>[['cedula','nombre','email',$col,'tipo_contrato'],['99000101','QA Importado','qa101@example.test','AuditOnly!2026','planta']],
 'Celadores'=>[['cedula','nombre','apellido','email',$col,'tipo_contrato'],['99000102','QA','Importado','qa102@example.test','AuditOnly!2026','planta']],
 'Ocupaciones'=>[['piso','ambiente','cedula_instructor','fecha','jornada'],['Piso QA Uno','QA Importado','99000101',$future,'tarde']]
];
foreach($fixtures as $kind=>$rows) {
 $file=tempnam(sys_get_temp_dir(),'saga_qa_'); $f=fopen($file,'w');foreach($rows as $row)fputcsv($f,$row);fclose($f);
 $c=$sessions['admin'];
 curl_setopt_array($c,[CURLOPT_URL=>'http://127.0.0.1:8097/controllers/ProcesarCarga'.$kind.'.php',CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['sede_id'=>'1','csrf_token'=>$tokens['admin'],'archivo'=>new CURLFile($file,'text/csv','prueba.csv')]]);
 $raw=curl_exec($c);preg_match('/^Location:\s*(.*)$/mi',$raw,$m);$loc=trim($m[1]??'');
 result('importacion CSV '.$kind,str_contains($loc,'creados=1')||str_contains($loc,'registrados=1'),['redirect'=>$loc,'status'=>curl_getinfo($c,CURLINFO_HTTP_CODE)]);
 unlink($file);
}
$col='contrase'.chr(195).chr(177).'a';
foreach (['99000101','99000102'] as $cedula) {
 result('clave importada protegida '.$cedula,password_verify('AuditOnly!2026',val("SELECT `$col` FROM usuarios WHERE cedula='$cedula'")));
}
$r=req('instructor','controllers/ObtenerNotificaciones.php');$data=json_decode($r['body'],true);
result('JSON de notificaciones valido',is_array($data));
$r=req('instructor','controllers/MarcarNotificacionesLeidas.php',['csrf_token'=>$tokens['instructor'],'todas'=>'1']);
result('marcar todas notificaciones leidas',(int)val('SELECT COUNT(*) FROM notificaciones WHERE usuario_id=2 AND leida=0')===0,['status'=>$r['status'],'body'=>substr($r['body'],0,200)]);
foreach(['ambientes','instructores','celadores','ocupaciones'] as $kind) {
 $r=req('admin','controllers/DescargarPlantilla'.ucfirst($kind).'.php?sede_id=1');
 result('descargar plantilla '.$kind,$r['status']===200 && str_starts_with($r['body'],'PK'),['status'=>$r['status']]);
}
file_put_contents(__DIR__.'/auditoria_extra_resultados.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
echo 'TOTAL '.count($results).' FALLOS '.count(array_filter($results,fn($r)=>!$r['ok'])).PHP_EOL;
