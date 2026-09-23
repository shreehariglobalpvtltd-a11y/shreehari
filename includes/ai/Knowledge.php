<?php
declare(strict_types=1);
if (!defined('SHG_APP')) { http_response_code(403); exit; }

final class AiKnowledge
{
    public static function published(array $ctx): array
    {
        $rows=Database::fetchAll("SELECT * FROM ai_kb_articles WHERE publication_status='published'
            AND verification_status='verified' AND (effective_from IS NULL OR effective_from<=NOW())
            AND (review_after IS NULL OR review_after>NOW()) ORDER BY id DESC LIMIT 500");
        return array_values(array_filter($rows,static function($a)use($ctx){
            $roles=json_decode($a['applicable_roles'],true)?:[];
            return in_array('public',$roles,true) || in_array($ctx['role'],$roles,true);
        }));
    }

    public static function search(string $q,array $ctx): array
    {
        $q=AiLanguage::normalize($q);
        $words=preg_split('/[^\p{L}\p{M}\p{N}]+/u',$q,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $rank=[];
        foreach(self::published($ctx) as $a){
            $hay=AiLanguage::normalize(implode(' ',array_map(static fn($k)=>(string)($a[$k]??''),
                ['canonical_title','keywords','synonyms','roman_nepali_examples','roman_hindi_examples','canonical_answer','nepali_content','hindi_content'])));
            $terms=preg_split('/[^\p{L}\p{M}\p{N}]+/u',$hay,-1,PREG_SPLIT_NO_EMPTY)?:[];
            $score=0;
            foreach(array_unique($words) as $w){
                if(mb_strlen($w)<3){continue;}
                if(in_array($w,$terms,true)){ $score+=2; }
                elseif(preg_match('/^[a-z]{5,}$/',$w)) {
                    foreach($terms as $t){if(strlen($t)>4 && strlen($t)<30 && levenshtein($w,$t)===1){$score++;break;}}
                }
            }
            if($q===AiLanguage::normalize($a['canonical_title'])){$score+=20;}
            if($score>0){$a['_score']=$score;$rank[]=$a;}
        }
        usort($rank,static fn($a,$b)=>$b['_score']<=>$a['_score']);
        return array_slice($rank,0,5);
    }

    public static function answer(array $a,string $lang): string
    {
        $field=match($lang){'ne'=>'nepali_content','hi'=>'hindi_content',default=>'english_content'};
        return trim((string)($a[$field]??'')) ?: $a['canonical_answer'];
    }

    public static function save(array $input,int $actor,?int $id=null): int
    {
        $title=trim((string)($input['canonical_title']??'')); $answer=trim((string)($input['canonical_answer']??''));
        if($title===''||mb_strlen($title)>200||$answer===''||mb_strlen($answer)>16000){throw new RuntimeException('Provide a title and answer within the limits.');}
        $state=(string)($input['publication_status']??'draft');
        if(!in_array($state,['draft','needs_review','published','outdated','archived'],true)){throw new RuntimeException('Invalid publication state.');}
        $source=trim((string)($input['source_reference']??''));
        if($source===''||mb_strlen($source)>255){throw new RuntimeException('Source evidence is required.');}
        $roles=array_values(array_intersect((array)($input['applicable_roles']??['public']),['public','customer','agent','counter','support','manager','accountant','official','superadmin']));
        if($roles===[]){throw new RuntimeException('Select an audience.');}
        $data=['canonical_title'=>$title,'canonical_answer'=>$answer,'category'=>mb_substr((string)($input['category']??'company'),0,80),
            'applicable_roles'=>json_encode($roles),'source_type'=>mb_substr((string)($input['source_type']??'admin'),0,40),
            'source_reference'=>$source,'publication_status'=>$state,'verification_status'=>$state==='published'?'verified':'unverified','updated_by'=>$actor];
        foreach(['nepali_content','hindi_content','english_content','roman_nepali_examples','roman_hindi_examples','keywords','synonyms'] as $f){$data[$f]=mb_substr((string)($input[$f]??''),0,16000);}
        foreach(['effective_from','review_after'] as $f){
            $v=trim((string)($input[$f]??''));
            if($v!=='' && strtotime($v)===false){throw new RuntimeException('Invalid review/effective date.');}
            $data[$f]=$v!==''?date('Y-m-d H:i:s',strtotime($v)):null;
        }
        return Database::transaction(static function()use($data,$id,$actor){
            $old=$id?Database::fetchForUpdate('SELECT * FROM ai_kb_articles WHERE id=:id',['id'=>$id])[0]??null:null;
            if($id&&!$old){throw new RuntimeException('Article not found.');}
            if($old){
                Database::run('INSERT IGNORE INTO ai_kb_versions(article_id,version,snapshot,created_by) VALUES(:a,:v,:s,:u)',
                    ['a'=>$id,'v'=>$old['version'],'s'=>json_encode($old,JSON_UNESCAPED_UNICODE),'u'=>$actor]);
                $data['version']=(int)$old['version']+1;
                Database::update('ai_kb_articles',$data,'id=:id',['id'=>$id]);
            }else{
                $data['slug']='article-'.bin2hex(random_bytes(8));$data['created_by']=$actor;
                $id=Database::insert('ai_kb_articles',$data);
            }
            Database::run('INSERT IGNORE INTO ai_kb_chunks(article_id,version,content) VALUES(:a,:v,:c)',
                ['a'=>$id,'v'=>$data['version']??1,'c'=>$data['canonical_title']."\n".$data['canonical_answer']]);
            return (int)$id;
        });
    }

    public static function unknown(string $q,string $lang): void
    {
        $q=AiPrivacy::redact(AiLanguage::normalize($q));
        Database::run('INSERT INTO ai_unanswered_questions(question_hash,normalized_question,language) VALUES(:h,:q,:l)
            ON DUPLICATE KEY UPDATE frequency=frequency+1, updated_at=NOW()', ['h'=>hash('sha256',$q),'q'=>$q,'l'=>$lang]);
    }
}
