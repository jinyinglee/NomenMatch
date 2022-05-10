import pymysql
import pandas as pd

db_settings = {
    "host": '',
    "port": '',
    "user": '',
    "password": '',
    "db": '',
}

conn = pymysql.connect(**db_settings)

query1 = """
CREATE TEMPORARY TABLE IF NOT EXISTS table2 AS (
    SELECT tmp.taxon_id, 
    MAX(IF (tmp.taxon_rank = 'Kingdom', `name`, NULL)) AS `kingdom` ,
    MAX(IF (tmp.taxon_rank = 'Phylum' , `name`, NULL)) AS `phylum` ,
    MAX(IF (tmp.taxon_rank = 'Class' , `name`, NULL)) AS `class` ,
    MAX(IF (tmp.taxon_rank = 'Order' , `name`, NULL)) AS `order` ,  
    MAX(IF (tmp.taxon_rank = 'Family' , `name`, NULL)) AS `family` ,  
    MAX(IF (tmp.taxon_rank = 'Genus' , `name`, NULL)) AS `genus`  
    FROM (
        SELECT th.child_taxon_id AS taxon_id, tn.name, 
            r.display ->> '$."en-us"' AS taxon_rank 
        FROM api_taxon_hierarchy th 
        JOIN api_taxon t ON th.parent_taxon_id = t.taxon_id 
        JOIN taxon_names tn ON t.accepted_taxon_name_id = tn.id 
        JOIN ranks r ON tn.rank_id = r.id
        WHERE th.child_taxon_id IN (select taxon_id FROM api_taxon) 
    ) AS tmp
    GROUP BY tmp.taxon_id
);
"""

query ="""
SELECT tn.id AS namecode, at.accepted_taxon_name_id AS accepted_namecode, CONCAT(tn.name, ' ' , tn.formatted_authors) AS scientific_name,
       tn.id AS name_url_id, at.accepted_taxon_name_id AS accepted_name_url_id, 
	   CONCAT_WS(',', at.common_name_c, at.alternative_name_c) AS common_name_c, r.display ->> '$."en-us"' AS taxon_rank, 
       t2.genus, t2.family, t2.order, t2.class, t2.phylum, t2.kingdom
FROM taxon_names tn
LEFT JOIN api_taxon_usages atu ON tn.id = atu.taxon_name_id
LEFT JOIN api_taxon at ON atu.taxon_id = at.taxon_id
LEFT JOIN ranks r ON tn.rank_id = r.id
LEFT JOIN table2 t2 ON at.taxon_id = t2.taxon_id"""
with conn.cursor() as cursor:
    cursor.execute(query1)
    cursor.execute(query)
    results = cursor.fetchall()
    results = pd.DataFrame(results, columns=['namecode', 'accepted_namecode', 'scientific_name', 'name_url_id', 'accepted_name_url_id', 'common_name_c', 'taxon_rank',
                                            'genus', 'family', 'order', 'class', 'phylum', 'kingdom'])
# id 轉成 int
results[['namecode','accepted_namecode','name_url_id', 'accepted_name_url_id' ]] = results[['namecode','accepted_namecode','name_url_id', 'accepted_name_url_id' ]].fillna(0)
results = results.astype({'accepted_namecode': "int", 'namecode': 'int', 'name_url_id': 'int', 'accepted_name_url_id': 'int'})

# 把 0 改成 None
results[['namecode','accepted_namecode','name_url_id', 'accepted_name_url_id' ]] = results[['namecode','accepted_namecode','name_url_id', 'accepted_name_url_id' ]].replace({0: None})

# scientific name 會有換行符號
results.scientific_name = results.scientific_name.str.replace(r'[\n\s]+', ' ')

# 階層全部小寫
results['taxon_rank'] = results['taxon_rank'].str.lower()

# 把自己的階層拿掉
for i in results.index:
    if i % 10000 == 0:
        print(i)
    taxon_rank = results.taxon_rank[i]
    if taxon_rank in ['genus', 'family', 'order', 'class', 'phylum', 'kingdom']:
        results.loc[i, taxon_rank] = None

results.to_csv('source_taicol_20220510.csv', header=None, index=False, sep='\t')