<?php
/**
 * User: Arris
 *
 * Class Clubs
 * Namespace: Units
 *
 * Date: 04.02.2018, time: 18:48
 */

namespace RPGCAtlas\Units;

use ReCaptcha\ReCaptcha;
use RPGCAtlas\Classes\StaticConfig;
use RPGCAtlas\Classes\Template;
use RPGCAtlas\Classes\DBStatic;

class Clubs
{
    /**
     * Рисует страницу: Список клубов, доступных для изменения текущему пользователю
     *
     * @return string
     */
    public function view_clubs() {
        $template = new Template('admin_view_list.html', '$/templates/clubs');

        $dbi = DBStatic::getInstance();

        $table = $dbi::$_table_prefix . 'clubs';

        $query = "SELECT * FROM {$table} ORDER BY `id` DESC";

        $dataset = [];
        foreach ($dbi->getConnection()->query($query)->fetchAll() as $row) {
            $data = $row;
            $data['coords'] = "{$row['lat']} / {$row['lng']}";

            // еще нужно определить реального владельца по айди (id_owner)
            //@todo: реальный владелец (для админа показывает логин владельца, для владельца - "Я"

            $dataset[ $row['id'] ] = $data;
        }

        $template->set('dataset', $dataset);

        $template->set('summary', [
            // клубов всего
            'clubs_total'   =>  count($dataset),

            // кол-во клубов, у которых is_public = 1
            'clubs_visible' =>  count(array_filter($dataset, function($data){ return !!$data['is_public']; }) )
        ]);

        $template->set('href', [
            'club_add'      =>  url('club_form_add'),
            'club_edit'     =>  url('club_form_edit'),
            'profile'       =>  url('profile_view'),
            'frontpage'     =>  url('frontpage')
        ]);

        return preg_replace('/^\h*\v+/m', '', $template->render());
    }

    /**
     * Рисует форму добавления нового клуба
     *
     * @return string
     */
    public function form_club_add() {
        $template = new Template('form_add.html', '$/templates/clubs');

        $template->set('html/title', "Добавление клуба");

        $template->set('href', [
            'profile'           =>  url('profile_view'),
            'frontpage'         =>  url('frontpage'),
            'clubs_list'        =>  url('clubs_list'),
            'form_action_submit'=>  url('club_callback_add'),
            'ajax_get_city'     =>  url('ajax_get_city_by_coords')
        ]);
        $template->set('options', [
            'captcha_enabled'   =>  StaticConfig::get('google_recaptcha/enabled'),
            'captcha_sitekey'   =>  StaticConfig::get('google_recaptch/site_key')
        ]);

        return $template->render();
    }

    /**
     * Коллбэк формы добавления нового клуба
     * @return string
     */
    public function callback_club_add()
    {
        $dbi = DBStatic::getInstance();
        $table = $dbi::$_table_prefix . 'clubs';

        $query = "
        INSERT INTO {$table}
        (
          `id_owner`,
          `is_public`,
          `lat`,
          `lng`,
          `title`,
          `desc`,
          `address`,
          `address_city`,
          `banner_horizontal`,
          `banner_vertical`,
          `url_site`,
          `ipv4_add`
        )
        VALUES
        (
          :id_owner,
          :is_public,
          :lat,
          :lng,
          :title,
          :desc,
          :address,
          :address_city,
          :banner_horizontal,
          :banner_vertical,
          :url_site,
          INET_ATON(:ipv4_add)
        )
        ";

        $sth = $dbi->getConnection()->prepare($query);

        $dataset = [
            "id_owner"  =>  1,
            "is_public" =>  input('club:add:is_public') ? 1 : 0,
            "lat"       =>  input('club:add:lat'),
            "lng"       =>  input('club:add:lng'),
            "title"     =>  input('club:add:title'),
            "desc"      =>  input('club:add:desc'),
            "address"   =>  input('club:add:address'),
            "address_city" => input('club:add:address_city'),
            "banner_horizontal" =>  input('club:add:banner_horizontal'),
            "banner_vertical"   =>  input('club:add:banner_vertical'),
            "url_site"       =>  input('club:add:url_site'),
            "ipv4_add"  =>  getIp()
        ];
        if (!$dataset['address_city']) {
            $dataset['address_city'] = getCityByCoords($dataset['lat'], $dataset['lng'])['city'];
        }

        try {
            $sth->execute($dataset);
        } catch (\PDOException $e) {
            dd($e->getMessage()); //@todo: MONOLOG
        }

        response()->redirect( url('clubs_list') );
    }

    /* ============ редактирование =============== */
    public function form_club_edit($id) {
        $dbi = DBStatic::getInstance();
        $table = $dbi::$_table_prefix . 'clubs';
        $query = "SELECT * FROM {$table} WHERE `id` = :id ORDER BY `id` DESC LIMIT 1";

        $sth = $dbi->getConnection()->prepare($query);
        $sth->execute([
            'id'    =>  $id
        ]);
        $dataset = $sth->fetch();

        //@todo: по $dataset['id_owner'] получаем владельца

        $dataset['title'] = htmlspecialchars($dataset['title'], ENT_QUOTES | ENT_HTML5);

        $template = new Template('form_edit.html', '$/templates/clubs');

        $template->set('dataset', $dataset);

        $template->set('html/title', "Редактирование клуба");
        $template->set('href', [
            'profile'           =>  url('profile_view'),
            'frontpage'         =>  url('frontpage'),
            'clubs_list'        =>  url('clubs_list'),
            'ajax_get_city'     =>  url('ajax_get_city_by_coords'),
            'form_action_submit'=>  url('club_callback_edit', ['id' => $id]),
            'form_action_delete'=>  url('club_callback_delete', ['id' => $id]),
            'form_action_toggle'=>  url('club_callback_toggle', ['id' => $id]),
        ]);
        $template->set('options', [
            'captcha_enabled'   =>  StaticConfig::get('google_recaptcha/enabled'),
            'captcha_sitekey'   =>  StaticConfig::get('google_recaptch/site_key')
        ]);

        return $template->render();
    }

    public function callback_club_edit($id) {
        $dbi = DBStatic::getInstance();
        $table = $dbi::$_table_prefix . 'clubs';

        $query = "
        UPDATE {$table} SET
        `id_owner` = :id_owner,
        `is_public` = :is_public,
        `lat` = :lat,
        `lng` = :lng,
        `title` = :title,
        `desc` = :desc,
        `address` = :address,
        `address_city` = :address_city,
        `banner_horizontal` = :banner_horizontal,
        `banner_vertical` = :banner_vertical,
        `url_site` = :url_site,
        `ipv4_edit` = :ipv4_edit
        WHERE `id` = :id
        ";

        $sth = $dbi->getConnection()->prepare($query);

        $dataset = [
            "id"        =>  input('club:edit:id'),
            "id_owner"  =>  input('club:edit:id_owner'),
            "is_public" =>  input('club:edit:is_public') ? 1 : 0,
            "lat"       =>  input('club:edit:lat'),
            "lng"       =>  input('club:edit:lng'),
            "title"     =>  input('club:edit:title'),
            "desc"      =>  input('club:edit:desc'),
            "address"   =>  input('club:edit:address'),
            "banner_horizontal" =>  input('club:edit:banner_horizontal'),
            "banner_vertical"   =>  input('club:edit:banner_vertical'),
            "url_site"       =>  input('club:edit:url_site'),
            "ipv4_edit"     =>  getIp()
        ];
        if (!$dataset['address_city']) {
            $dataset['address_city'] = getCityByCoords($dataset['lat'], $dataset['lng'])['city'];
        }

        try {
            $sth->execute($dataset);
        } catch (\PDOException $e) {
            dd($e->getMessage()); //@todo: MONOLOG
        }

        response()->redirect( url('clubs_list') );
    }

    public function callback_club_delete($id) {
        $dbi = DBStatic::getInstance();
        $table = $dbi::$_table_prefix . 'clubs';

        $query = "
        DELETE FROM {$table}
        WHERE `id` = :id
        ";

        $sth = $dbi->getConnection()->prepare($query);

        $dataset = [
            "id" =>  $id,
        ];

        try {
            $sth->execute($dataset);
        } catch (\PDOException $e) {
            dd($e->getMessage()); //@todo: MONOLOG
        }

        response()->redirect( url('clubs_list') );
    }

    public function callback_club_visibility_toggle($id)
    {
        $dbi = DBStatic::getInstance();
        $table = $dbi::$_table_prefix . 'clubs';

        $query = "UPDATE {$table} SET `id_public` = NOT `is_public` WHERE `id` = :id";

        $sth = $dbi->getConnection()->prepare($query);
        $dataset = [
            "id" =>  $id,
        ];

        try {
            $sth->execute($dataset);
        } catch (\PDOException $e) {
            dd($e->getMessage()); //@todo: MONOLOG
        }

        return json_encode([TRUE]);
    }

}