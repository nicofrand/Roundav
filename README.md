# Roundav

This plugin is a fork of [Roundcube-Plugin-roundav](https://github.com/messagerie-melanie2/Roundcube-Plugin-roundav), itself “largely inspired by the plugin kolab_file”.

It can be used to connect to a WebDAV storage (like Nextcloud) from Roundcube to save attachments to the WebDAV storage or insert attachments from it..

> **/!\ WARNING**: This is an alpha version, don't use it in production unless you are absolutely sure of what you do

License
-------

This plugin is released under the [GNU Affero General Public License Version 3](https://www.gnu.org/licenses/agpl-3.0.html).

Install
-------

1. Place this plugin folder into plugins directory of Roundcube.
1. Run `composer install --no-dev` inside this folder.
1. Add `roundav` to `$config['plugins']` in your Roundcube config.
