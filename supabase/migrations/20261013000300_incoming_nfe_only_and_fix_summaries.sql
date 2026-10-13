-- resNFe is distributed only to the NF-e recipient and omits the <dest> block.
-- Complete old summary rows from the queried company's registered identity.
with summary_recipients as (
  select distinct on (s.organization_id, s.fiscal_document_id)
    s.organization_id,
    s.fiscal_document_id,
    c.cnpj as recipient_cnpj,
    c.name as recipient_name
  from public.fiscal_document_sources s
  join public.companies c
    on c.id = s.company_id
   and c.organization_id = s.organization_id
  where s.source_type = 'nfe_distribution'
    and s.schema_name like 'resNFe%'
    and s.fiscal_document_id is not null
  order by s.organization_id, s.fiscal_document_id, s.company_id
)
update public.fiscal_documents d
set recipient_cnpj = r.recipient_cnpj,
    recipient_name = r.recipient_name,
    is_purchase = true
from summary_recipients r
where d.id = r.fiscal_document_id
  and d.organization_id = r.organization_id;

-- Correct existing NF-e rows from their real destination tax ID. This hides
-- invoices distributed to a carrier or an authorized third party from the
-- recipient's invoice list while retaining their archived source XML.
update public.fiscal_documents d
set is_purchase = exists (
  select 1
  from public.fiscal_document_sources s
  join public.companies c
    on c.id = s.company_id
   and c.organization_id = s.organization_id
  where s.fiscal_document_id = d.id
    and s.organization_id = d.organization_id
    and s.source_type = 'nfe_distribution'
    and upper(c.cnpj) = upper(coalesce(d.recipient_cnpj, ''))
)
where exists (
  select 1
  from public.fiscal_document_sources s
  where s.fiscal_document_id = d.id
    and s.organization_id = d.organization_id
    and s.source_type = 'nfe_distribution'
);
