# NomenMatch v2
  
This branch is developed based on [original version of NomenMatch](https://github.com/TaiBIF/NomenMatch/tree/master).

## Main changes compared to original version

1. matching name of higher ranks such as order, family, genus
2. matching common names (in traditional chinese) 

## Installing

1) build image

```bash
 $ docker-compose build
```

2) run devel

```bash
 $ docker-compose up -d
```

3) create solr core & set custom config (only first time)

```bash
  $ docker-compose exec solr bash
  $ ./bin/solr create_core -c taxa
  $ cp solrconfig.xml /var/solr/data/taxa/conf
  $ cp managed-schema /var/solr/data/taxa/conf
  $ exit
  $ docker-compose restart solr
```

4) prepare data

- prepare source data csv and put it in `source-data` folder 
- copy conf/sources.csv to `source-data` folder if `source-data` don't have sources.csv
- modified souces.csv to map source id and source info

```bash
  $ cp conf/sources.csv source-data 
```

5) import data (example: TaiCOL)

```bash
  $ docker-compose exec php bash
  $ cd /code/workspace
  $ php ./importChecklistToSolr.php ../source-data/<taicol-checklist.csv> taicol
```

# Source data format

Tab seperated, see workspace/data/example.csv  
Column definition:
- namecode
- accepted_namecode
- scientific_name (full name or canonical form is ok)
- name_url_id (the id which can be used to create a valid url to a taxon name page)
- accepted_name_url_id (the id which can be used to create a valid url to an accepted taxon name page, if the name is a synonym)
- family
- order
- class
- phylum
- kingdom
- simple_name
- name_status

> **Note**
> example code for generating source data could be found in `./scripts` folder.

# Describe source data

Edit conf/sources.example.csv and rename to sources.csv  
Column definition:  
- source_id
- source_name
- url_base (when combined with [accepted] name_url_id, it becomes valid url for the taxon, blah blah)  
for example,
- citation format
- source data page
- date (of source data fetched, downloaded, or created)

# Delete source data in solr

Under workspace dir, run  
```
php clean_source.php {source_id}
```  
to remove a specific source from solr, or run  
```
php clean_source.php all
```
to remove all sources at once.  
If this script doesn't work, usually it means java heap space out of memory. Try to restart solr and then run again.  

# Demo
- [NomenMatch v2](http://match.taibif.tw/v2/index.html)
