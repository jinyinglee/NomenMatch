import pymysql
import pandas as pd
import numpy as np
from datetime import date


db_settings = {
    "host": '',
    "port": '',
    "user": '',
    "password": '',
    "db": '', }

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

query = """
SELECT tn.id AS namecode, at.accepted_taxon_name_id AS accepted_namecode, CONCAT(tn.name, ' ' , tn.formatted_authors) AS scientific_name,
       tn.id AS name_url_id, at.accepted_taxon_name_id AS accepted_name_url_id, 
	   CONCAT_WS(',', at.common_name_c, at.alternative_name_c) AS common_name_c, r.display ->> '$."en-us"' AS taxon_rank, 
       t2.genus, t2.family, t2.order, t2.class, t2.phylum, t2.kingdom, tn.name AS simple_name
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
                                             'genus', 'family', 'order', 'class', 'phylum', 'kingdom', 'simple_name'])
# id 轉成 int
results[['namecode', 'accepted_namecode', 'name_url_id', 'accepted_name_url_id']] = results[['namecode', 'accepted_namecode', 'name_url_id', 'accepted_name_url_id']].fillna(0)
results = results.astype({'accepted_namecode': "int", 'namecode': 'int', 'name_url_id': 'int', 'accepted_name_url_id': 'int'})

# 把 0 改成 None
results[['namecode', 'accepted_namecode', 'name_url_id', 'accepted_name_url_id']] = results[['namecode', 'accepted_namecode', 'name_url_id', 'accepted_name_url_id']].replace({0: None})

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

results.to_csv('source_taicol_20220516.csv', header=None, index=False, sep='\t')


# ------- generate from old TaiCOL ------- #


"""
Index(['kingdom', 'kingdom_c', 'phylum', 'phylum_c', 'class', 'class_c',
       'order', 'order_c', 'family', 'family_c', 'name_code', 'name', 'genus',
       'genus_c', 'species', 'infraspecies_marker', 'infraspecies',
       'infraspecies2_marker', 'infraspecies2', 'author', 'author2',
       'accepted_name_code', 'status_id', 'is_accepted_name', 'is_endemic',
       'is_marine', 'alien_status', 'is_fossil', 'common_name_c',
       'redlist_wang', 'redlist_wang_ass', 'redlist_chen', 'iucn_code',
       'iucn_assessment', 'iucn_dateassessed', 'iucn_id', 'coa_redlist_code',
       'cites_code', 'redlist2017', 'redlist2017_note', 'datelastmodified'],
      dtype='object')

     """


# infraspecies_marker
# infraspecies2_marker

# 'var.': 'variety'
# 'f.': 'form'
# 'subvar.': 'subvariety'
# 'subsp.':  'subspecies'
# 'fo.': 'form'
# 'f.sp.': 'special-form'
# 'cv.':  'cultivar'
# 'ab.':  'aberration'
# 'm.': 'morph'

rank_map = {'var.': 'variety', 'f.': 'form', 'subvar.': 'subvariety', 'subsp.':  'subspecies', 'fo.': 'form',
            'f.sp.': 'special-form', 'cv.':  'cultivar', 'ab.':  'aberration', 'm.': 'morph'}


# 'Human Virus'
# 'Plant Virus'
# 'Animal Virus'

df = pd.read_csv('/Users/taibif/Downloads/TaiwanSpecies20220415_UTF8.csv')

df = df.replace({np.nan: None})
df['simple_name'] = df['name']

# 僅有 種 & 種下

for i in df.index:
    if i % 1000 == 0:
        print(i)
    row = df.iloc[i]
    infra = row.infraspecies2_marker if row.infraspecies2_marker else row.infraspecies_marker
    if rank_map.get(infra):
        infra = rank_map.get(infra)
    df.loc[i, 'taxon_rank'] = infra if infra else 'species'
    author = row.author2 if row.author2 else row.author
    if author:
        df.loc[i, 'name'] += ' ' + author



df['common_name_c'] = df['common_name_c'].str.replace(';', ',')


"""
	/**
	 * 0 namecode
	 * 1 accepted_namecode
	 * 2 scientific_name
	 * 3 name_url_id
	 * 4 accepted_url_id
	 * 5 common_name_c
	 * 6 taxon_rank
	 * 7 genus
	 * 8 family
	 * 9 order
	 * 10 class
	 * 11 phylum
	 * 12 kingdom
	 * 13 simple_name
	 */

"""


df = df[['name_code', 'accepted_name_code', 'name', 'name_code', 'accepted_name_code',
         'common_name_c', 'taxon_rank', 'genus', 'family', 'order', 'class', 'phylum', 'kingdom', 'simple_name']]

today = date.today()

today_str = today.strftime("%Y%m%d")


df = df.replace({np.nan: None})

df.to_csv(f'./source-data/source_taicol_old_{today_str}.csv', sep='\t', header=None, index=False)

source = pd.read_table('./source-data/sources.csv', sep='\t', header=None)
# id 不可動
# name
source.loc[source[0]=='taicol',1] = 'TaiCOL' 
# url_base
source.loc[source[0]=='taicol',2] = 'https://taibnet.sinica.edu.tw/chi/taibnet_species_detail.php?name_code='
# citation
source.loc[source[0]=='taicol',3] = 'K. F. Chung, K. T. Shao, Catalogue of life in Taiwan. Web electronic publication. version 2022 https://taibnet.sinica.edu.tw'
# url
source.loc[source[0]=='taicol',4] = 'https://taibnet.sinica.edu.tw'
# version 下載檔案上的日期
source.loc[source[0]=='taicol',5] = '2022-04-15'

source.to_csv('./source-data/sources.csv', sep='\t', header=None, index=None)

