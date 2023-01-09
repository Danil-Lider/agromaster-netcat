<?php

class nc_List extends nc_Essence {
    protected $db;

    const SORT_NAME = 1;
    const SORT_PRIORITY = 2;

    public function __construct(){
        parent::__construct();

        $this->essence = 'Classificator';

        $nc_core = nc_Core::get_object();

        if (is_object($nc_core->db)) {
            $this->db = $nc_core->db;
        }
    }

    public function get_by_id($id_or_keyword, $item = '', $reset = false) {
        if ($reset || !isset($this->data[$id_or_keyword])) {
            if (is_numeric($id_or_keyword)) {
                $where_cond = "`{$this->essence}_ID` = " . (int)$id_or_keyword;
            } elseif (preg_match('/^\w+$/', $id_or_keyword)) {
                $where_cond = "`Table_Name` = '{$this->db->escape($id_or_keyword)}'";
            } else {
                throw new UnexpectedValueException('Wrong list ID or keyword');
            }

            $data = $this->db->get_row("SELECT * FROM `{$this->essence}` WHERE " . $where_cond, ARRAY_A);

            if ($data) {
                $this->data[$data[$this->essence . '_ID']] = $this->data[$data['Table_Name']] = $data;
            } else {
                $this->data[$id_or_keyword] = array();
            }
        }

        // if item requested return item value
        if ($item && is_array($this->data[$id_or_keyword])) {
            return array_key_exists($item, $this->data[$id_or_keyword]) ? $this->data[$id_or_keyword][$item] : null;
        }

        return $this->data[$id_or_keyword];
    }

    public function get_by_id_or_keyword($id_or_keyword, $item = "", $reset = false) {
        return $this->get_by_id($id_or_keyword, $item, $reset);
    }

    public function update($id, $params) {
        $db = $this->db;

        $id = (int)$id;
        if (!$id || !is_array($params)) {
            return false;
        }

        $params_int = array(
            'System',
            'Sort_Type',
            'Sort_Direction',
        );

        $params_text = array(
            'Classificator_Name',
            'Table_Name',
            'CustomSettingsTemplate',
        );

        $query = array();

        foreach ($params as $k => $v) {
            if (in_array($k, $params_int)) {
                $query[] = "`" . $db->escape($k) . "` = " . (int)trim($v);
            } elseif (in_array($k, $params_text)) {
                $query[] = "`" . $db->escape($k) . "` = '" . $db->prepare($v) . "'";
            }
        }

        if (!empty($query)) {
            $db->query("UPDATE `" . $this->essence . "` SET " . join(",\n        ", $query) . " WHERE `" . $this->essence . "_ID` = " . $id);
            if ($db->is_error) {
                throw new nc_Exception_DB_Error($db->last_query, $db->last_error);
            }
        }

        $this->data = array();
        return true;
    }

    /**
     * Возвращает поле, по которому производится сортировка списка
     * @internal пока не является частью публичного API
     * @param $id_or_keyword
     * @return string
     */
    public function get_sort_field($id_or_keyword) {
        $list = $this->get_by_id($id_or_keyword);
        switch ($list['Sort_Type']) {
            case self::SORT_NAME:
                return $list['Table_Name'] . '_Name';
            case self::SORT_PRIORITY:
                return $list['Table_Name'] . '_Priority';
            default:
                return $list['Table_Name'] . '_ID';
        }
    }

    public function get_max_priority($list_id) {
        $table_name = $this->get_by_id($list_id, 'Table_Name');
        $max_priority = $this->db->get_var("SELECT MAX(" . $table_name . "_Priority) FROM " . $this->essence . "_" . $table_name);
        return $max_priority ?: 0;
    }

    public function get_items($list_id_keyword) {
        $table_name = $this->get_by_id($list_id_keyword, 'Table_Name');
        $items = array();
        if ($table_name) {
            $items = $this->db->get_results("SELECT * FROM `" . $this->essence . "_" . $table_name . "`", ARRAY_A);
            if ($items) {
                foreach($items AS $i => $item) {
                    $items[$i] = array_merge(
                        $items[$i],
                        array(
                            'ID' => $item[$table_name . '_ID'],
                            'Name' => $item[$table_name . '_Name'],
                            'Priority' => $item[$table_name . '_Priority'],
                        )
                    );
                }
            }
        }
        return $items;
    }

    public function get_item($list_id_or_keyword, $item_id) {
        $item = array();
        $table_name = $this->get_by_id($list_id_or_keyword, 'Table_Name');
        if ($table_name) {
            $item = $this->db->get_row("SELECT * FROM `" . $this->essence . "_" . $table_name . "` WHERE `".$table_name."_ID` = ".(int)$item_id, ARRAY_A);
            $item['Name'] = $item[$table_name . '_Name'];
            $item['ID'] = $item[$table_name . '_ID'];
            $item['Priority'] = $item[$table_name . '_Priority'];
        }
        return $item;
    }

    public function create_item($list_id, $item_properties) {
        $table_name = $this->get_by_id($list_id, 'Table_Name');

        $item_properties = $this->merge_mirror_fields($table_name, $item_properties);

        // если нет приоритета, то берем максимальный в текущем списке + 1
        if (!$item_properties[$table_name . '_Priority']) {
            $item_properties[$table_name . '_Priority'] = $this->get_max_priority($list_id) + 1;
        }

        $item_id = nc_db_table::make($this->essence . '_' . $table_name)->insert($item_properties);
        if (!$item_id) {
            throw new Exception("Unable to create list item \n" . $this->db->last_error);
        }
        return $item_id;
    }

    public function update_item($list_id, $item_id, $item_properties) {
        $table_name = $this->get_by_id($list_id, 'Table_Name');
        $item_properties = $this->merge_mirror_fields($table_name, $item_properties);
        nc_db_table::make($this->essence . '_' . $table_name, $table_name . '_ID')->update($item_properties);
        if ($this->db->is_error) {
            throw new nc_Exception_DB_Error($this->db->last_query, $this->db->last_error);
        }
    }

    /**
     * копируем значения из "зеркальных" свойств в "нормальные"
     * если "нормальные" заполнены, то они в приоритете
     * @param $table_name
     * @param $properties
     * @return mixed
     */
    private function merge_mirror_fields($table_name, $properties) {
        $mirror_keys = array('ID', 'Name', 'Priority');
        foreach ($properties AS $key => $value) {
            if (in_array($key, $mirror_keys)) {
                $property_key = $table_name . '_' . $key;
                if (!$properties[$property_key]) {
                    $properties[$property_key] = $value;
                }
                unset($properties[$key]);
            }
        }

        return $properties;
    }

    public function evaluate_item_settings($list_id, $item_settings) {
        $custom_settings = $this->get_by_id($list_id, 'CustomSettingsTemplate');
        if (!$custom_settings) {
            return array();
        }

        $a2f = new nc_a2f($custom_settings);
        if ($item_settings) {
            $a2f->set_values($item_settings);
        }
        return $a2f->get_values_as_array();
    }

}