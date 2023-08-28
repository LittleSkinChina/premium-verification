<?php

return [

    App\Events\PluginWasEnabled::class => function () {

        if (!Schema::hasTable('oauth_microsoftoidc_connections')) {
            Schema::create('oauth_microsoftoidc_connections', function ($table) {
                $table->increments('id');
                $table->integer('uid');
                $table->string('oid');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('premium_verification')) {
            Schema::create('premium_verification', function ($table) {
                $table->increments('id');
                $table->integer('uid')->unique();
                $table->integer('pid')->unique();
                $table->string('uuid', 32)->unique();
                $table->timestamps();
            });
        }
    },
];
