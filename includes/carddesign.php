<?php
declare(strict_types=1);
if(!defined('SHG_APP')){http_response_code(403);exit;}
final class CardDesign {
 public static function preset(string $name='royal'): array {
  $theme=match($name){'minimal'=>['#ffffff','#0a2a8f','#f07a1e'],'orange'=>['#fff8f0','#172b55','#e46a15'],default=>['#0b2457','#ffffff','#f07a1e']};
  $front=['logo'=>[.06,.04,.27,.14,20],'company'=>[.37,.06,.57,.1,25],'photo'=>[.08,.23,.32,.28,20],'name'=>[.08,.56,.84,.08,26],'role'=>[.08,.65,.82,.06,18],'code'=>[.08,.72,.7,.06,18],'route'=>[.08,.79,.62,.07,15],'verify'=>[.72,.78,.2,.18,12]];
  $back=['company'=>[.08,.06,.84,.1,24],'code'=>[.08,.20,.82,.08,22],'contact'=>[.08,.32,.83,.08,16],'route'=>[.08,.43,.82,.08,16],'phone'=>[.08,.54,.8,.07,18],'whatsapp'=>[.65,.65,.28,.26,11],'signature'=>[.08,.68,.4,.13,16],'notice'=>[.08,.9,.84,.06,10]];
  $vf=['logo'=>[.04,.05,.23,.23,20],'company'=>[.31,.08,.64,.12,28],'photo'=>[.05,.34,.22,.49,16],'name'=>[.31,.31,.63,.12,28],'role'=>[.31,.47,.62,.1,19],'code'=>[.31,.59,.62,.1,18],'route'=>[.31,.7,.42,.11,15],'verify'=>[.79,.67,.15,.26,11]];
  $vb=['company'=>[.06,.08,.86,.14,30],'phone'=>[.06,.32,.63,.12,25],'contact'=>[.06,.49,.63,.12,18],'route'=>[.06,.65,.58,.12,18],'whatsapp'=>[.77,.35,.17,.31,11],'notice'=>[.06,.87,.88,.07,12]];
  if($name==='minimal'){$front['photo']=[.34,.22,.32,.28,20];$front['name']=[.13,.55,.8,.09,26];$front['role']=[.13,.65,.78,.06,18];$vf['photo']=[.72,.29,.22,.49,16];$vf['name']=[.06,.34,.62,.12,28];$vf['role']=[.06,.5,.6,.1,19];$vf['code']=[.06,.63,.6,.1,18];$vf['verify']=[.52,.7,.14,.24,11];}
  if($name==='orange'){$front['photo']=[.55,.23,.36,.3,20];$front['name']=[.07,.58,.85,.1,28];$front['code']=[.07,.74,.6,.06,20];}
  $fields=[];foreach(['id_front'=>$front,'id_back'=>$back,'visiting_front'=>$vf,'visiting_back'=>$vb] as $surface=>$rows){foreach($rows as $key=>$box){$fields[$surface][]=['key'=>$key,'x'=>$box[0],'y'=>$box[1],'w'=>$box[2],'h'=>$box[3],'size'=>$box[4],'color'=>$theme[1],'text'=>'','visible'=>true];}}
  return ['preset'=>$name,'background'=>$theme[0],'ink'=>$theme[1],'accent'=>$theme[2],'orient_id'=>'portrait','orient_visiting'=>'landscape','bw'=>false,'fields'=>$fields];
 }
 public static function clean(array $in): array {
  $d=self::preset(in_array($in['preset']??'', ['royal','minimal','orange'],true)?$in['preset']:'royal');
  foreach(['background','ink','accent'] as $k){if(preg_match('/^#[a-f0-9]{6}$/i',(string)($in[$k]??''))){$d[$k]=$in[$k];}}
  foreach(['orient_id','orient_visiting'] as $k){if(in_array($in[$k]??'', ['portrait','landscape'],true)){$d[$k]=$in[$k];}}
  $d['bw']=!empty($in['bw']);
  foreach($d['fields'] as $surface=>$defaults){
   $input=$in['fields'][$surface]??null;if(!is_array($input)){continue;}$out=[];$seen=[];
   foreach(array_slice($input,0,25) as $field){if(!is_array($field)){continue;}$key=(string)($field['key']??'');if(!in_array($key,['logo','company','photo','name','role','code','route','verify','phone','contact','whatsapp','signature','notice','text'],true)||isset($seen[$key])){continue;}$seen[$key]=true;
    $f=['key'=>$key,'visible'=>($key==='verify'||$key==='code')?true:!empty($field['visible']),'text'=>Security::clean((string)($field['text']??''),160),'size'=>max(8,min(48,(float)($field['size']??18))),'color'=>preg_match('/^#[a-f0-9]{6}$/i',(string)($field['color']??''))?$field['color']:$d['ink']];
    foreach(['x','y'] as $axis){$f[$axis]=max(0,min(.94,is_numeric($field[$axis]??null)?(float)$field[$axis]:.05));}
    foreach(['w','h'] as $axis){$f[$axis]=max(.04,min(1-$f[$axis==='w'?'x':'y'],is_numeric($field[$axis]??null)?(float)$field[$axis]:.2));}$out[]=$f;
   }
   foreach($defaults as $f){if(in_array($f['key'],['code','verify'],true)&&!isset($seen[$f['key']])){$out[]=$f;}}
   $d['fields'][$surface]=$out;
  }return $d;
 }
}
