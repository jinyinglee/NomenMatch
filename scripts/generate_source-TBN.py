import pandas as pd
import numpy as np
from datetime import date

df = pd.read_csv('/Users/taibif/Documents/04-TaiCOL/tbntaxonbio-expor-6028-20220516094704-b69c6.csv')

df = df.replace({np.nan: None})

"""
Index(['taxonUUID', 'taxonRank', 'parentUUID', 'parentScientificName',
       'taxonGroup', 'superdomain', 'superdomainTW', 'domain', 'domainTW',
       'subdomain', 'subdomainTW', 'superkingdom', 'superkingdomTW', 'kingdom',
       'kingdomTW', 'subkingdom', 'subkingdomTW', 'superphylum',
       'superphylumTW', 'phylum', 'phylumTW', 'subphylum', 'subphylumTW',
       'superclass', 'superclassTW', 'class', 'classTW', 'subclass',
       'subclassTW', 'superorder', 'superorderTW', 'order', 'orderTW',
       'suborder', 'suborderTW', 'superfamily', 'superfamilyTW', 'family',
       'familyTW', 'subfamily', 'subfamilyTW', 'genus', 'genusTW', 'subgenus',
       'subgenusTW', 'specificEpithet', 'authorship', 'subspecies',
       'subspeciesAuthorship', 'variety', 'varietyAuthorship', 'form',
       'formAuthorship', 'cultigen', 'scientificName', 'vernacularName',
       'simplifiedScientificName', 'alternativeName', 'searchName',
       'nomenclaturalCode', 'endemism', 'nativeness', 'habitat',
       'taiCoLNameCode', 'taxonfileCladeID', 'protectedStatusTW',
       'protectedStatusVersionTW', 'categoryRedlistTW',
       'categoryRedlistVersionTW', 'categoryRedlistCriteriaTW', 'categoryIUCN',
       'categoryIUCNVersion', 'categoryIUCNCriteria', 'sensitiveCategory',
       'sensitiveCategoryVersion']
"""

"""
	/**
	 * 0 namecode taxonUUID
	 * 1 accepted_namecode taxonUUID
	 * 2 scientific_name scientificName
	 * 3 name_url_id taxonUUID
	 * 4 accepted_url_id taxonUUID
	 * 5 common_name_c vernacularName
	 * 6 taxon_rank taxonRank
	 * 7 genus
	 * 8 family
	 * 9 order
	 * 10 class
	 * 11 phylum
	 * 12 kingdom
	 * 13 simple_name simplifiedScientificName
	 */
"""

for i in df.index:
    if i % 1000 == 0:
        print(i)
    row = df.iloc[i]
    if row.taxonRank == 'infraspecies':
        rank_list = []
        if row.subspecies is not None:
            rank_list += ['subspecies']
        if row.variety is not None:
            rank_list += ['variety']
        if row.form is not None:
            rank_list += ['form']
        if row.cultigen is not None:
            rank_list += ['cultigen']
        df.loc[i, 'taxonRank'] = ','.join(rank_list)

df = df[['taxonUUID','taxonUUID','scientificName','taxonUUID','taxonUUID',
         'vernacularName','taxonRank','genus','family','order','class','phylum','kingdom','simplifiedScientificName']]

today = date.today()
today_str = today.strftime("%Y%m%d")

df = df.replace({np.nan: None})

df.to_csv(f'./source-data/source_tbn_{today_str}.csv', sep='\t', header=None, index=False)


source = pd.read_table('./source-data/sources.csv', sep='\t', header=None)
# id 不可動
# name
source.loc[source[0]=='tbn',1] = 'TBN' 
# url_base
source.loc[source[0]=='tbn',2] = 'https://www.tbn.org.tw/taxa/'
# citation
source.loc[source[0]=='tbn',3] = 'TBN：台灣生物多樣性網絡（2021）TBN首頁 https://www.tbn.org.tw/ 。瀏覽於 2022-06-07。行政院農業委員會特有生物研究保育中心。'
# url
source.loc[source[0]=='tbn',4] = 'https://www.tbn.org.tw/'
# version 下載檔案上的日期
source.loc[source[0]=='tbn',5] = '2022-06-07'

source.to_csv('./source-data/sources.csv', sep='\t', header=None, index=None)