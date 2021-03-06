<?php

class BaseModel extends Model{

    public function __construct($name='',$tablePrefix='',$connection='') {
        if('' !== $name || is_subclass_of($this,'BaseModel') ){
            // 如果是AdvModel子类或者有传入模型名称则获取字段缓存
        }else{
            // 空的模型 关闭字段缓存
            $this->autoCheckFields = false;
        }
        parent::__construct($name,$tablePrefix,$connection);
    }
    
    public function with($field, $op, $value=null){
        if($op === null){
            return $this->where($field);
        }
        if($value === null){
            return $this->where(array($field=>$op));
        }
        else{
            return $this->where(array($field=>array($op, $value)));
        }
    }
    
    public function withuser($user_id=null){
        if($user_id === null){
            return $this->where(array('user_id'=>user('id')));
        }
        else{
            return $this->where(array('user_id'=>$user_id));
        }
    }
    
    public function extract($field){
        $results = $this->select();
        $ret = array();
        foreach($results as $result){
            $ret[$result[$this->pk]] = $result[$field];
        }
        return $ret;
    }   
    
    public function select_as_map(){
        $results = $this->select();
        $ret = array();
        foreach($results as $result){
            $ret[$result[$this->pk]] = $result;
        }
        return $ret;
    }
    
    // rewrite select funciton to return an empty array instead of null.
    public function select($options=array()) {
        $result = parent::select($options);
        if($result === null){
            $result = array();
        }
        return $result;
    }
    
    //
    public function count(){
        $old_options = $this->options;
        $result = parent::count();
        $this->options = $old_options;
        return $result;
    }
    
    protected function _after_select(&$resultSet,$options) {
        if(isset($options['attach'])){
            foreach($options['attach'] as $attach){
                $this_ids = extract_field($resultSet, $attach[1]);
                $target = O($attach[0]);
                $that_map = $target->with($target->pk, 'in', $this_ids)->select_as_map();   // all the data needed in "that" table
                for($i=0;$i<count($resultSet);$i++){
                    if($attach[2]==null){    //without particular attach field
                        $resultSet[$i][$attach[0]] = $that_map[$resultSet[$i][$attach[1]]];
                    }
                    else{
                        $resultSet[$i][$attach[0].'_'.$attach[2]] = $that_map[$resultSet[$i][$attach[1]]][$attach[2]];
                    }
                }
            }
        }
        
        if(isset($options['fetch'])){
            $this_ids = extract_field($resultSet, $this->pk);
            foreach($options['fetch'] as $fetch){
                $target = O($fetch[0]);
                $that_records = $target->with($fetch[1], 'in', $this_ids)->select();
                $this_map = array();
                foreach($that_records as $record){
                    $this_map[$record[$fetch[1]]][] = $record;
                }
                for($i=0;$i<count($resultSet);$i++){
                    if(isset($this_map[$resultSet[$i][$this->pk]])){
                        $resultSet[$i][$fetch[0]] = $this_map[$resultSet[$i][$this->pk]];
                    }
                }
            }
        }
        
        if(isset($options['bridge'])){
            foreach($options['bridge'] as $bridge){
                // alias for all the fields in bridge
                $that_name = $bridge[0];
                $bridge_table_name = $bridge[1];
                $this_field = $bridge[2];
                $that_field = $bridge[3];
                    
                for($i=0;$i<count($resultSet);$i++){
                    $that_table = O($that_name);
                    $bridge_table = O($bridge_table_name);
                    $bridge_records = $bridge_table->with($this_field, $resultSet[$i][$this->pk])->select();
                    $bridge_results = array();
                    foreach($bridge_records as $bridge_record){
                        $that_result = $that_table->with($that_table->pk, $bridge_record[$that_field])->find();
                        $that_result['_bridge'] = $bridge_record;
                        array_push($bridge_results, $that_result);
                    }
                    $resultSet[$i][$that_name] = $bridge_results;
                }
            }
        }
        
        parent::_after_select($resultSet,$options);
    }
    
    /*
    * connect one-to-one or many-to-one information from another table
    * eg. 
    * you have: 
    * users: [id, name, email]
    * events: [id, name, user_id]
    * 
    * you use:
    * O('events')->attach('users', 'user_id')->select();
    *
    * result: [[ id, name, 'users'=>[id, name, email] ], ...]
    *
    * @param $table: the table to be attached
    * @param $with: the pk of the other table in this table.
    */
    public function attach($table, $with=null, $field=null){
        if(!isset($this->options['attach'])){
            $this->options['attach'] = array();
        }
        if($with === null) $with = $table.'_id';
        array_push($this->options['attach'], array($table, $with, $field));
        return $this;
    }
    
    /*
    * connect one-to-many information from another table
    * eg. 
    * you have: 
    * users: [id, name, email]
    * events: [id, name, user_id]
    * 
    * you use:
    * O('users')->fetch('events', 'user_id')->select();
    *
    * result: [[ id, name, email, 'events'=>[[id, name, user_id], ...] ], ...]
    *
    * @param $table: name of the other table
    * @param $with: the pk of this table in the other table.
    */
    public function fetch($table, $with=null){
        if(!isset($this->options['fetch'])){
            $this->options['fetch'] = array();
        }
        if($with === null) $with = $this->name.'_id';
        array_push($this->options['fetch'], array($table, $with));
        return $this;
    }
    
    /*
    * connect many-to-many information from another table
    * eg. 
    * you have: 
    * posts: [id, name, content]
    * tags: [id, name]
    * posts_tags: [id, posts_id, tags_id, tagger]
    * 
    * you use:
    * O('posts')->bridge('tags', 'posts_tags', 'tags_id', 'posts_id')->select();
    * -or- O('posts')->bridge('tags');
    *
    * result: [[ id, name, content, 'tags'=>[[id, name, _bridge=>[id, posts_id, tags_id, tagger]], ...] ], ...]
    *
    * @param $table: name of the other table 
    * @param $bridge_table: name of the bridge table
    * @param $with: the pk of this table.
    * @param $and_with: the pk of the other table.
    */
    public function bridge($table, $bridge_table=null, $with=null, $and_with=null){
        if(!isset($this->options['bridge'])){
            $this->options['bridge'] = array();
        }
        if($bridge_table === null) $bridge_table = $this->name.'_'.$table;
        if($with === null) $with = $this->name.'_id';
        if($and_with === null) $with = $table.'_id';
        array_push($this->options['bridge'], array($table, $bridge_table, $with, $and_with));
        return $this;
    }
}