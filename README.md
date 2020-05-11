CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Installation
 * Recommended modules
 * Configuration
 * Upgrading


INTRODUCTION
------------

The Smart IP Redirect to Locale (with Cookie) overrides language 
negotiation and redirects users to a language depending on their IP address
based on a country code mapping configuration page.


INSTALLATION
------------

The installation of this module is like other Drupal modules.

 1. Enable the 'Smart IP Redirect to Locale' module along with
    its dependencies (Locale and Smart IP) in 'Extend'. 
   (/admin/modules)
 2. Set up adminstration permissions. (/admin/people/permissions#module-smart_ip_locale_redirect)


RECOMMENDED MODULES
-------------------

 * 	Smart IP MaxMind GeoIP2 Precision web service (sub module of Smart IP)


CONFIGURATION
-------------

 1. Go to Smart IP settings page to select the main Smart IP
    data source. (/admin/config/people/smart_ip)
 2. Go to Smart IP Locale Redirect Settings to map each country code to 
    a site language. (/admin/config/search/smart_ip_locale_redirect)
 3. Specify user agents to be excluded from redirection such as Googlebot and bingbot.
