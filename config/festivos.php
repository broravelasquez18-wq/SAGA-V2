<?php
// Calendario colombiano: Ley 51 de 1983; computo gregoriano de Pascua.
function festivos_colombia(int $year): array {
    static $cache = [];
    if (isset($cache[$year])) return $cache[$year];
    if ($year < 1583 || $year > 9999) return [];
    $a=$year%19; $b=intdiv($year,100); $c=$year%100;
    $d=intdiv($b,4); $e=$b%4; $f=intdiv($b+8,25); $g=intdiv($b-$f+1,3);
    $h=(19*$a+$b-$d-$g+15)%30; $i=intdiv($c,4); $k=$c%4;
    $l=(32+2*$e+2*$i-$h-$k)%7; $m=intdiv($a+11*$h+22*$l,451);
    $v=$h+$l-7*$m+114;
    $pascua=new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, intdiv($v,31), $v%31+1));
    $dias=[];
    foreach (['01-01','05-01','07-20','08-07','12-08','12-25'] as $md) $dias[]="$year-$md";
    foreach (['01-06','03-19','06-29','08-15','10-12','11-01','11-11'] as $md) {
        $fecha=new DateTimeImmutable("$year-$md");
        $delta=(8-(int)$fecha->format('N'))%7;
        $dias[]=$fecha->modify("+$delta days")->format('Y-m-d');
    }
    foreach ([-3,-2,43,64,71] as $delta) $dias[]=$pascua->modify("$delta days")->format('Y-m-d');
    $dias=array_values(array_unique($dias)); sort($dias);
    return $cache[$year]=$dias;
}
