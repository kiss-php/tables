# Tables
Tables is an easy library for the quick use of mysql database.
<!---->

## How to use
To use you only need to add the configuration of your Databases in your .env file (using dotenv or another similar).
You will need to define your tables in a folder and you will be available to start to work with your database.

### .env
Required...
``` yaml

DB_TYPE=mysql
DB_HOST=
DB_USER=
DB_PASS=
DB_DTBS=
DB_PORT=

DB_LITLE_TYPE=sqlite
DB_LITLE_PATH=

```

## Required folder with entities definitions
Example...
```
name[varchar(255)]:null
surname[varchar(255)]:null
what[varchar(255)]:null
```