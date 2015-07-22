aliance_tax
===

This is a simple web application that monitors members tax contributions to the alliance or other similar entity or resource. This was developed for Massively Dynamic [M.DYN] when we were in the TJA alliance, as they required an alliance tax of 5 mil ISK per member.

It's a simple PHP file that pulls data from the EVE Online API servers and parses it to determine who has paid and who hasn't. Configuration is done via `config.ini`, and API details are added to `protected/apiDetails.ini` (a sample file can be found in the root project directory). Rudimentary (and very insecure) authentication is done via the HTTP headers that are sent with the in-game browser. If it's determined that your character has permissions (again, via the config), you may ignore specific erroneous Journal entries. Since this is a very mild privilege the exploitation of which is harmless, I did not write a secure authentication system.

This project is hosted on GitHub AS-IS for archival purposes - while I may fiddle with it here and there for giggles, I no longer actively maintain it. Feel free to fork it and extend the functionality of it, such as integrating it with EVE Online's Single-Sign On for more secure character authentication.

![untitled](https://cloud.githubusercontent.com/assets/3904767/8815410/d9b65e46-2fe6-11e5-9aac-641167815554.png)
