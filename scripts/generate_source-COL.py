import numpy as np
import pandas as pd
from datetime import date

today = date.today()
today_str = today.strftime("%Y%m%d")

df = pd.read_table('/Users/taibif/Documents/04-TaiCOL/2022-03-21_dwca/Taxon.tsv', sep='\t', usecols=['dwc:taxonID', 'dwc:acceptedNameUsageID','dwc:scientificName','dwc:taxonRank','dwc:parentNameUsageID'])
df['common_name_c'] = None
df['kingdom'] = None
df['phylum'] = None
df['class'] = None
df['order'] = None
df['family'] = None
df['genus'] = None

# 把階層串回來
id_name_dict = dict(zip(df['dwc:taxonID'], df['dwc:scientificName']))
id_rank_dict = dict(zip(df['dwc:taxonID'], df['dwc:taxonRank']))
parent_dict = dict(zip(df['dwc:taxonID'], df['dwc:parentNameUsageID']))

def find_parent(x, i):
    value = parent_dict.get(x, np.nan)
    if value != np.nan: 
        parent_rank = id_rank_dict.get(value)
        if parent_rank in ['genus', 'family', 'order', 'class', 'phylum', 'kingdom']:
            parent_name = id_name_dict.get(value)
            df.loc[i, parent_rank] = parent_name
        if parent_rank not in ['kingdom', None]:
            find_parent(value, i) 

for i in df.index: #4463570
    key = df.iloc[i]['dwc:acceptedNameUsageID'] if df.iloc[i]['dwc:acceptedNameUsageID'] != np.nan else df.iloc[i]['dwc:taxonID']
    find_parent(key,i)
    if i % 1000 == 0:
        print(i)

df = df.replace({np.nan: None})

df = df[['dwc:taxonID','dwc:acceptedNameUsageID','dwc:scientificName','dwc:taxonID','dwc:acceptedNameUsageID', 
         'common_name_c', 'dwc:taxonRank', 'genus', 'family', 'order', 'class', 'phylum', 'kingdom']]

df.to_csv(f'./source-data/source_col_{today_str}.csv', sep='\t', header=None, index=False)

# - namecode
# - accepted_namecode
# - scientific_name (full name or canonical form is ok)
# - name_url_id (the id which can be used to create a valid url to a taxon name page)
# - accepted_name_url_id (the id which can be used to create a valid url to an accepted taxon name page, if the name is a synonym)
# - common_name_c
# - taxon_rank
# - genus
# - family
# - order
# - class
# - phylum
# - kingdom
