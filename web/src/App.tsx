import { useEffect, useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import type { FormEvent, ReactNode } from 'react'
import type { Session } from '@supabase/supabase-js'
import {
  ArrowDownToLine, ArrowRight, Building2, CalendarDays, Check, ChevronDown,
  CircleAlert, Clock3, FileSearch, FileText, Filter, Plus,
  RefreshCw, Search, ShieldCheck, SlidersHorizontal, X,
} from 'lucide-react'
import { supabase } from './lib/supabase'
import type { Company, FiscalDocument, SyncCursor } from './lib/supabase'

type Membership = { organization_id: string; role: string; organizations: { name: string } | null }
type Page = 'documents' | 'companies'
type Notice = { tone: 'success' | 'error' | 'info'; text: string } | null

const NFE_ENVIRONMENT = import.meta.env.VITE_NFE_TPAMB === '1' ? 1 : 2
const ALLOW_SIGNUP = import.meta.env.VITE_ALLOW_SIGNUP !== 'false'
const NFE_CURSOR_TYPE = NFE_ENVIRONMENT === 1 ? 'nfe55' : 'nfe55_hom'
const NFE_DOCUMENT_LABEL = NFE_ENVIRONMENT === 1 ? 'NF-e 55' : 'NF-e 55 · Homologação'
const BRAZILIAN_STATES = [
  ['AC', 'Acre'], ['AL', 'Alagoas'], ['AP', 'Amapá'], ['AM', 'Amazonas'],
  ['BA', 'Bahia'], ['CE', 'Ceará'], ['DF', 'Distrito Federal'], ['ES', 'Espírito Santo'],
  ['GO', 'Goiás'], ['MA', 'Maranhão'], ['MT', 'Mato Grosso'], ['MS', 'Mato Grosso do Sul'],
  ['MG', 'Minas Gerais'], ['PA', 'Pará'], ['PB', 'Paraíba'], ['PR', 'Paraná'],
  ['PE', 'Pernambuco'], ['PI', 'Piauí'], ['RJ', 'Rio de Janeiro'], ['RN', 'Rio Grande do Norte'],
  ['RS', 'Rio Grande do Sul'], ['RO', 'Rondônia'], ['RR', 'Roraima'], ['SC', 'Santa Catarina'],
  ['SP', 'São Paulo'], ['SE', 'Sergipe'], ['TO', 'Tocantins'],
] as const

const digits = (value: string) => value.replace(/\D/g, '')
const formatCnpj = (value: string) => {
  const n = digits(value).slice(0, 14)
  return n.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
    .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2')
}
const formatMoney = (value: number | null) => value == null
  ? '—'
  : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value)
const formatDate = (value: string | null) => value
  ? new Intl.DateTimeFormat('pt-BR').format(new Date(value))
  : '—'
const shortKey = (value: string | null) => value ? `${value.slice(0, 6)}…${value.slice(-4)}` : 'Resumo DF-e'

function App() {
  const [session, setSession] = useState<Session | null>(null)
  const [authReady, setAuthReady] = useState(false)
  const [page, setPage] = useState<Page>('documents')
  const [selectedCompany, setSelectedCompany] = useState('all')
  const [search, setSearch] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [companyDialog, setCompanyDialog] = useState(false)
  const [syncDialog, setSyncDialog] = useState(false)
  const [notice, setNotice] = useState<Notice>(null)
  const [busy, setBusy] = useState(false)
  const queryClient = useQueryClient()

  useEffect(() => {
    if (!supabase) { setAuthReady(true); return }
    supabase.auth.getSession().then(({ data }) => {
      setSession(data.session)
      setAuthReady(true)
    })
    const { data: listener } = supabase.auth.onAuthStateChange((_event, value) => setSession(value))
    return () => listener.subscription.unsubscribe()
  }, [])

  const { data: membership, isLoading: membershipLoading, error: membershipError } = useQuery({
    queryKey: ['membership', session?.user.id],
    enabled: Boolean(session?.user.id && supabase),
    queryFn: async () => {
      const { data, error } = await supabase!.from('organization_members')
        .select('organization_id, role, organizations(name)')
        .eq('user_id', session!.user.id).limit(1).maybeSingle()
      if (error) throw error
      return data as Membership | null
    },
  })

  const organizationId = membership?.organization_id
  const { data: companies = [], isLoading: companiesLoading } = useQuery({
    queryKey: ['companies', organizationId],
    enabled: Boolean(organizationId),
    queryFn: async () => {
      const { data, error } = await supabase!.from('companies').select('*')
        .eq('organization_id', organizationId!).order('name')
      if (error) throw error
      return data as Company[]
    },
  })

  const { data: documents = [], isLoading: documentsLoading, dataUpdatedAt } = useQuery({
    queryKey: ['documents', organizationId, selectedCompany, search, dateFrom, dateTo],
    enabled: Boolean(organizationId),
    queryFn: async () => {
      let request = supabase!.from('fiscal_documents')
        .select('*, company:companies(name,cnpj)', { count: 'exact' })
        .eq('organization_id', organizationId!)
        .order('issued_at', { ascending: false, nullsFirst: false })
        .range(0, 99)
      if (selectedCompany !== 'all') request = request.eq('company_id', selectedCompany)
      if (dateFrom) request = request.gte('issued_at', `${dateFrom}T00:00:00`)
      if (dateTo) request = request.lte('issued_at', `${dateTo}T23:59:59`)
      const cleaned = search.trim().replace(/[^\p{L}\p{N} ]/gu, '').slice(0, 60)
      if (cleaned) {
        const pattern = `%${cleaned}%`
        request = request.or(`issuer_name.ilike.${pattern},recipient_name.ilike.${pattern},issuer_cnpj.ilike.${pattern},recipient_cnpj.ilike.${pattern},access_key.ilike.${pattern},document_number.ilike.${pattern}`)
      }
      const { data, error } = await request
      if (error) throw error
      return (data ?? []) as FiscalDocument[]
    },
  })

  const latestRun = useMemo(() => companies.reduce<string | null>((latest, item) => {
    const time = (item as Company & { last_synced_at?: string }).last_synced_at
    return time && (!latest || time > latest) ? time : latest
  }, null), [companies])

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['documents', organizationId] })

  if (!supabase) return <SetupNotice />
  if (!authReady) return <div className="boot-screen"><div className="brand-mark"><FileText size={20} /></div><span>Preparando seu espaço fiscal…</span></div>
  if (!session) return <AuthScreen onNotice={setNotice} />
  if (membershipLoading) return <div className="boot-screen"><div className="spinner" /><span>Carregando organização…</span></div>
  if (membershipError) return <div className="boot-screen"><p>Não foi possível carregar sua organização.</p><button className="button button-secondary" onClick={() => queryClient.invalidateQueries({ queryKey: ['membership'] })}>Tentar novamente</button></div>
  if (!membership) return <CreateOrganization user={session.user.email ?? ''} onNotice={setNotice} />

  const totalValue = documents.reduce((sum, item) => sum + Number(item.total_amount ?? 0), 0)
  const companyName = selectedCompany === 'all' ? 'Todas as empresas' : companies.find((item) => item.id === selectedCompany)?.name ?? 'Empresa'

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <a className="brand" href="#documentos" onClick={() => setPage('documents')}>
          <span className="brand-mark"><FileText size={19} strokeWidth={2.2} /></span>
          <span className="brand-name">fiscal<span>.</span></span>
        </a>
        <div className="org-switcher"><div className="org-avatar">{(membership.organizations?.name ?? 'O').slice(0, 1).toUpperCase()}</div><div className="org-copy"><small>Organização</small><strong>{membership.organizations?.name ?? 'Minha organização'}</strong></div><ChevronDown size={15} /></div>
        <div className="nav-label">ESPAÇO FISCAL</div>
        <nav className="main-nav" aria-label="Navegação principal">
          <button className={page === 'documents' ? 'nav-item active' : 'nav-item'} onClick={() => setPage('documents')}><FileSearch size={18} /><span>Documentos</span></button>
          <button className={page === 'companies' ? 'nav-item active' : 'nav-item'} onClick={() => setPage('companies')}><Building2 size={18} /><span>Empresas</span></button>
        </nav>
        <div className="sidebar-foot"><span className="connected-dot" /> Ambiente local <span className="local-tag">LOCAL</span></div>
      </aside>

      <main className="main-area">
        <header className="topbar">
          <div className="mobile-brand"><span className="brand-mark"><FileText size={18} /></span><b>fiscal<span>.</span></b></div>
          <div className="breadcrumb"><span>Workspace</span><span className="crumb-slash">/</span><strong>{page === 'documents' ? 'Documentos' : 'Empresas'}</strong></div>
          <div className="top-actions">
            <span className="secure-label"><ShieldCheck size={15} /> Área protegida</span>
            <button className="avatar-button" title={`Conta de ${session.user.email}`} onClick={async () => { await supabase!.auth.signOut(); queryClient.clear() }}>{session.user.email?.slice(0, 1).toUpperCase()}<span className="sr-only">Sair da conta</span></button>
          </div>
        </header>

        <div className="content-wrap">
          {page === 'documents' ? (
            <>
              <section className="page-heading">
                <div><div className="eyebrow"><span className="eyebrow-line" /> CENTRAL DE DOCUMENTOS</div><h1>Busca fiscal</h1><p>Encontre notas e acompanhe o que chega para suas empresas.</p></div>
                <div className="heading-actions"><button className="button button-secondary" onClick={() => refresh()} disabled={documentsLoading}><RefreshCw size={16} className={documentsLoading ? 'spin' : ''} />Atualizar</button><button className="button button-primary" onClick={() => companies.length ? setSyncDialog(true) : setCompanyDialog(true)}><ArrowDownToLine size={17} />Sincronizar NF-e</button></div>
              </section>

              <section className="summary-grid" aria-label="Resumo dos documentos">
                <article className="summary-card"><div className="summary-icon green"><FileText size={18} /></div><div><span>Documentos encontrados</span><strong>{documents.length}</strong><small>{documents.length === 100 ? 'Mostrando os 100 mais recentes' : 'Nesta busca'}</small></div></article>
                <article className="summary-card"><div className="summary-icon blue"><Building2 size={18} /></div><div><span>Empresa selecionada</span><strong className="summary-company">{companies.length ? companyName : 'Nenhuma empresa'}</strong><small>{companies.length} {companies.length === 1 ? 'empresa conectada' : 'empresas conectadas'}</small></div></article>
                <article className="summary-card"><div className="summary-icon amber"><Clock3 size={18} /></div><div><span>Última consulta</span><strong className="summary-company">{latestRun ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(latestRun)) : 'Ainda não consultado'}</strong><small>{formatMoney(totalValue)} em documentos visíveis</small></div></article>
              </section>

              <section className="search-panel">
                <div className="search-row">
                  <label className="search-input"><Search size={18} /><span className="sr-only">Buscar documentos</span><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por emitente, destinatário, CNPJ ou chave…" /></label>
                  <label className="company-select"><Building2 size={16} /><span className="sr-only">Filtrar por empresa</span><select value={selectedCompany} onChange={(event) => setSelectedCompany(event.target.value)}><option value="all">Todas as empresas</option>{companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}</select><ChevronDown size={15} /></label>
                </div>
                <div className="filter-row"><div className="filter-caption"><SlidersHorizontal size={15} /><span>Filtros</span></div><label className="date-filter"><CalendarDays size={15} /><span>De</span><input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} /></label><label className="date-filter"><CalendarDays size={15} /><span>Até</span><input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} /></label><span className="type-pill"><span className="type-dot" /> NF-e · Modelo 55{NFE_ENVIRONMENT === 2 ? ' · Homologação' : ' · Produção'}</span><span className="updated-at">{dataUpdatedAt ? `Atualizado às ${new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' }).format(dataUpdatedAt)}` : 'Aguardando consulta'}</span></div>
              </section>

              <section className="results-card">
                <div className="results-heading"><div><h2>Documentos recebidos</h2><p>{documents.length} resultado{documents.length === 1 ? '' : 's'} nesta página</p></div><button className="icon-button" title="Limpar filtros" onClick={() => { setSearch(''); setDateFrom(''); setDateTo(''); setSelectedCompany('all') }}><Filter size={17} /><span className="sr-only">Limpar filtros</span></button></div>
                {documentsLoading ? <div className="loading-state"><div className="spinner" /><span>Buscando documentos…</span></div> : documents.length ? <DocumentTable documents={documents} onOpen={(document) => { void openDocumentXml(document).catch((error) => setNotice({ tone: 'error', text: error instanceof Error ? error.message : 'Não foi possível abrir o XML.' })) }} /> : <EmptyDocuments hasCompanies={companies.length > 0} onAddCompany={() => setCompanyDialog(true)} onSync={() => setSyncDialog(true)} />}
              </section>
              <footer className="content-footer"><span>Dados isolados por organização e empresa</span><span><span className="footer-dot" /> Conectado ao Supabase local</span></footer>
            </>
          ) : (
            <CompaniesPage companies={companies} loading={companiesLoading} onAdd={() => setCompanyDialog(true)} onSync={(id) => { setSelectedCompany(id); setSyncDialog(true) }} />
          )}
        </div>
      </main>

      {companyDialog && <CompanyDialog organizationId={organizationId!} onClose={() => setCompanyDialog(false)} onSaved={async () => { setCompanyDialog(false); await queryClient.invalidateQueries({ queryKey: ['companies', organizationId] }); setNotice({ tone: 'success', text: 'Empresa adicionada.' }) }} onNotice={setNotice} />}
      {syncDialog && <SyncDialog companies={companies} initialCompanyId={selectedCompany === 'all' ? companies[0]?.id : selectedCompany} organizationId={organizationId!} onClose={() => setSyncDialog(false)} onNotice={setNotice} onBusy={setBusy} onDone={async () => { setSyncDialog(false); await queryClient.invalidateQueries({ queryKey: ['companies', organizationId] }); await queryClient.invalidateQueries({ queryKey: ['documents', organizationId] }) }} />}
      {notice && <div className={`toast ${notice.tone}`} role="status"><span>{notice.tone === 'error' ? <CircleAlert size={17} /> : <Check size={17} />}</span>{notice.text}<button onClick={() => setNotice(null)} aria-label="Fechar aviso"><X size={15} /></button></div>}
      {busy && <div className="busy-scrim" aria-live="polite">Sincronizando com a SEFAZ…</div>}
    </div>
  )
}

function SetupNotice() {
  return <div className="setup-screen"><div className="setup-card"><span className="brand-mark"><FileText size={20} /></span><p className="eyebrow">CONFIGURAÇÃO LOCAL</p><h1>Conecte o Supabase</h1><p>Copie as chaves locais geradas pelo Supabase para o arquivo <code>.env</code> e reinicie o Compose.</p><div className="setup-command">npx supabase start</div></div></div>
}

function AuthScreen({ onNotice }: { onNotice: (notice: Notice) => void }) {
  const [isSignup, setIsSignup] = useState(false)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [working, setWorking] = useState(false)
  const [message, setMessage] = useState('')
  const submit = async (event: FormEvent) => {
    event.preventDefault(); if (!supabase) return
    setWorking(true); setMessage('')
    try {
      if (isSignup) {
        const { data, error } = await supabase.auth.signUp({ email, password })
        if (error) throw error
        if (data.session) setMessage('Conta criada. Vamos preparar sua organização…')
        else setMessage('Conta criada. Confirme o e-mail e entre para continuar.')
      } else {
        const { error } = await supabase.auth.signInWithPassword({ email, password })
        if (error) throw error
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Não foi possível autenticar.'
      setMessage(`Erro: ${message}`)
      onNotice({ tone: 'error', text: message })
    }
    finally { setWorking(false) }
  }
  return <div className="auth-screen"><div className="auth-decoration"><div className="auth-orbit orbit-one"/><div className="auth-orbit orbit-two"/><div className="auth-brand"><span className="brand-mark"><FileText size={20}/></span><b>fiscal<span>.</span></b></div><div className="auth-manifesto"><span className="eyebrow">SEU ARQUIVO FISCAL</span><h1>Documentos em ordem.<br/><em>Decisões com clareza.</em></h1><p>Uma visão organizada das notas fiscais que chegam para suas empresas.</p></div><div className="auth-decoration-foot"><span>NF-e · modelo 55</span><span>Ambiente protegido</span></div></div><div className="auth-form-side"><form className="auth-form" onSubmit={submit}><span className="eyebrow">{isSignup ? 'PRIMEIRO ACESSO' : 'BEM-VINDO DE VOLTA'}</span><h2>{isSignup ? 'Crie sua conta' : 'Entre na sua conta'}</h2><p>Acesse seu espaço fiscal para continuar.</p><label className="field-label">E-mail<input type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label><label className="field-label">Senha<input type="password" autoComplete={isSignup ? 'new-password' : 'current-password'} minLength={8} value={password} onChange={(event) => setPassword(event.target.value)} required /></label>{message && <div className={`inline-message ${message.startsWith('Erro:') ? 'error' : ''}`} role="status">{message}</div>}<button className="button button-primary auth-submit" disabled={working}>{working ? <><span className="button-spinner"/>Processando…</> : <>{isSignup ? 'Criar conta' : 'Entrar'}<ArrowRight size={16}/></>}</button>{ALLOW_SIGNUP && <button type="button" className="text-button" onClick={() => { setIsSignup(!isSignup); setMessage('') }}>{isSignup ? 'Já tem conta? Entrar' : 'Ainda não tem conta? Criar acesso'}</button>}<div className="auth-privacy"><ShieldCheck size={16}/>Seus dados fiscais ficam no seu ambiente Supabase.</div></form></div></div>
}

function CreateOrganization({ user, onNotice }: { user: string; onNotice: (notice: Notice) => void }) {
  const [name, setName] = useState('')
  const [working, setWorking] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const queryClient = useQueryClient()
  const create = async (event: FormEvent) => {
    event.preventDefault(); if (!supabase) return
    setWorking(true); setErrorMessage('')
    try {
      const { error } = await supabase.rpc('create_organization', { org_name: name.trim() })
      if (error) throw error
      await queryClient.invalidateQueries({ queryKey: ['membership'] })
      onNotice({ tone: 'success', text: 'Organização criada.' })
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Não foi possível criar a organização.'
      setErrorMessage(message)
      onNotice({ tone: 'error', text: message })
    } finally { setWorking(false) }
  }
  return <div className="setup-screen"><form className="setup-card" onSubmit={create}><span className="brand-mark"><Building2 size={20}/></span><p className="eyebrow">UM ESPAÇO PARA {user.toUpperCase()}</p><h1>Crie sua organização</h1><p>Empresas e documentos ficam agrupados neste espaço.</p><label className="field-label">Nome da organização<input autoFocus value={name} onChange={(event) => setName(event.target.value)} placeholder="Ex.: Meu escritório" required maxLength={100}/></label>{errorMessage && <div className="inline-message error" role="alert">{errorMessage}</div>}<button className="button button-primary" disabled={working}>{working ? 'Criando…' : 'Continuar'}<ArrowRight size={16}/></button></form></div>
}

function CompanyDialog({ organizationId, onClose, onSaved, onNotice }: { organizationId: string; onClose: () => void; onSaved: () => void; onNotice: (notice: Notice) => void }) {
  const [name, setName] = useState(''); const [cnpj, setCnpj] = useState(''); const [state, setState] = useState('MG'); const [working, setWorking] = useState(false)
  const save = async (event: FormEvent) => {
    event.preventDefault(); if (!supabase) return
    const normalized = digits(cnpj)
    if (normalized.length !== 14) { onNotice({ tone: 'error', text: 'Informe um CNPJ com 14 dígitos.' }); return }
    setWorking(true)
    const { error } = await supabase.from('companies').insert({ organization_id: organizationId, name: name.trim(), cnpj: normalized, state })
    setWorking(false)
    if (error) onNotice({ tone: 'error', text: error.code === '23505' ? 'Este CNPJ já está cadastrado nesta organização.' : error.message })
    else onSaved()
  }
  return <Modal title="Adicionar empresa" description="Informe a UF do estabelecimento vinculado ao CNPJ." onClose={onClose}><form className="dialog-form" onSubmit={save}><label className="field-label">Nome empresarial<input autoFocus value={name} onChange={(event) => setName(event.target.value)} required maxLength={160} placeholder="Razão social"/></label><label className="field-label">CNPJ<input inputMode="numeric" value={formatCnpj(cnpj)} onChange={(event) => setCnpj(digits(event.target.value))} required placeholder="00.000.000/0000-00"/></label><label className="field-label">UF do estabelecimento<select value={state} onChange={(event) => setState(event.target.value)} required>{BRAZILIAN_STATES.map(([code, label]) => <option key={code} value={code}>{code} · {label}</option>)}</select></label><div className="dialog-note"><ShieldCheck size={16}/>A consulta busca documentos disponíveis para este CNPJ, inclusive de emitentes de outros estados. O certificado A1 será solicitado ao sincronizar.</div><div className="dialog-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary" disabled={working}>{working ? 'Salvando…' : 'Adicionar empresa'}</button></div></form></Modal>
}

function SyncDialog({ companies, initialCompanyId, organizationId, onClose, onNotice, onBusy, onDone }: { companies: Company[]; initialCompanyId?: string; organizationId: string; onClose: () => void; onNotice: (notice: Notice) => void; onBusy: (busy: boolean) => void; onDone: () => void }) {
  const [companyId, setCompanyId] = useState(initialCompanyId ?? '')
  const [file, setFile] = useState<File | null>(null)
  const [password, setPassword] = useState('')
  const [working, setWorking] = useState(false)
  const [phase, setPhase] = useState('')
  const company = companies.find((item) => item.id === companyId)

  const sync = async (event: FormEvent) => {
    event.preventDefault(); if (!supabase || !company || !file) return
    if (!file.name.toLowerCase().endsWith('.pfx') && !file.name.toLowerCase().endsWith('.p12')) { onNotice({ tone: 'error', text: 'Selecione um certificado A1 .pfx ou .p12.' }); return }
    setWorking(true); onBusy(true); setPhase('Consultando o último NSU salvo…')
    let runId: string | null = null
    try {
      const { data: cursorData, error: cursorError } = await supabase.from('sync_cursors').select('*').eq('company_id', company.id).eq('document_type', NFE_CURSOR_TYPE).maybeSingle()
      if (cursorError) throw cursorError
      const cursor = cursorData as SyncCursor | null
      if (cursor?.next_sync_at && new Date(cursor.next_sync_at).getTime() > Date.now()) {
        throw new Error(`A SEFAZ pediu para aguardar até ${new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(cursor.next_sync_at))}.`)
      }
      const { data: run, error: runError } = await supabase.from('sync_runs').insert({ organization_id: organizationId, company_id: company.id, document_type: NFE_CURSOR_TYPE, status: 'running', cursor_before: cursor?.last_nsu ?? '000000000000000', started_at: new Date().toISOString() }).select('id').single()
      if (runError) throw runError
      runId = run.id
      setPhase('Conectando à distribuição oficial da NF-e…')
      const payload = { ambiente: NFE_ENVIRONMENT, uf: company.state, cnpj: company.cnpj, ult_nsu: cursor?.last_nsu ?? '000000000000000' }
      const form = new FormData()
      form.set('payload', JSON.stringify(payload))
      form.set('certificado', file, file.name)
      form.set('certificado_password', password)
      const response = await fetch('/api/nfe/distribuir', { method: 'POST', body: form })
      const result = await response.json() as { success?: boolean; message?: string; data?: { cStat?: string; xMotivo?: string; ult_nsu?: string; max_nsu?: string; documentos?: Array<{ nsu: string; schema?: string; xml?: string | null; decode_error?: string | null }> } }
      if (!response.ok || !result.success || !result.data) throw new Error(result.message ?? 'A consulta à SEFAZ falhou.')
      const data = result.data
      if (data.cStat === '656') {
        const { error: cooldownError } = await supabase.from('sync_cursors').upsert({
          organization_id: organizationId, company_id: company.id, document_type: NFE_CURSOR_TYPE,
          last_nsu: cursor?.last_nsu ?? '000000000000000', max_nsu: data.max_nsu ?? cursor?.max_nsu ?? null,
          last_run_at: new Date().toISOString(), next_sync_at: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
        }, { onConflict: 'company_id,document_type' })
        if (cooldownError) throw cooldownError
        throw new Error('A SEFAZ informou consumo indevido. A consulta ficará bloqueada por uma hora.')
      }
      if (data.cStat !== '137' && data.cStat !== '138') {
        throw new Error(`A SEFAZ respondeu ${data.cStat ?? 'sem código'}: ${data.xMotivo ?? 'consulta não concluída.'}`)
      }
      setPhase('Salvando XMLs e metadados…')
      const saved = [] as Array<Record<string, unknown>>
      for (const item of data.documentos ?? []) {
        if (!item.xml) throw new Error(item.decode_error ?? `A SEFAZ retornou o documento NSU ${item.nsu} sem XML.`)
        const parsed = parseFiscalXml(item.xml)
        const externalId = parsed.accessKey ?? item.nsu
        const path = `${organizationId}/${company.id}/55/${item.nsu}-${externalId}.xml`
        const { error: uploadError } = await supabase.storage.from('fiscal-xml').upload(path, new Blob([item.xml], { type: 'application/xml' }), { contentType: 'application/xml', upsert: true })
        if (uploadError) throw uploadError
        const digest = await sha256(item.xml)
        saved.push({ organization_id: organizationId, company_id: company.id, document_type: NFE_DOCUMENT_LABEL, external_id: externalId, access_key: parsed.accessKey, nsu: item.nsu, issuer_name: parsed.issuerName, issuer_cnpj: parsed.issuerCnpj, recipient_name: parsed.recipientName, recipient_cnpj: parsed.recipientCnpj, document_number: parsed.number, series: parsed.series, issued_at: parsed.issuedAt, total_amount: parsed.total, situation: parsed.situation, storage_path: path, xml_sha256: digest })
      }
      if (saved.length) {
        const { error: saveError } = await supabase.from('fiscal_documents').upsert(saved, { onConflict: 'company_id,document_type,external_id' })
        if (saveError) throw saveError
      }
      const returnedNsu = data.ult_nsu ?? payload.ult_nsu
      const reachedEnd = data.cStat === '137' || (data.max_nsu && returnedNsu === data.max_nsu)
      const nextSyncAt = reachedEnd ? new Date(Date.now() + 60 * 60 * 1000).toISOString() : null
      const { error: stateError } = await supabase.from('sync_cursors').upsert({ organization_id: organizationId, company_id: company.id, document_type: NFE_CURSOR_TYPE, last_nsu: returnedNsu, max_nsu: data.max_nsu ?? null, last_run_at: new Date().toISOString(), next_sync_at: nextSyncAt }, { onConflict: 'company_id,document_type' })
      if (stateError) throw stateError
      const { error: companyError } = await supabase.from('companies').update({ last_synced_at: new Date().toISOString() }).eq('id', company.id)
      if (companyError) throw companyError
      const { error: finishError } = await supabase.from('sync_runs').update({ status: 'completed', cursor_after: returnedNsu, document_count: saved.length, finished_at: new Date().toISOString(), cstat: data.cStat, message: data.xMotivo ?? null }).eq('id', runId)
      if (finishError) throw finishError
      onNotice({ tone: 'success', text: saved.length ? `${saved.length} documento${saved.length === 1 ? '' : 's'} recebido${saved.length === 1 ? '' : 's'} e salvo${saved.length === 1 ? '' : 's'}.` : 'Consulta concluída. Nenhum documento novo foi disponibilizado.' })
      onDone()
    } catch (error) {
      if (runId) await supabase.from('sync_runs').update({ status: 'failed', finished_at: new Date().toISOString(), message: error instanceof Error ? error.message : 'Falha ao sincronizar.' }).eq('id', runId)
      onNotice({ tone: 'error', text: error instanceof Error ? error.message : 'Falha ao sincronizar os documentos.' })
    } finally { setWorking(false); onBusy(false); setPhase(''); setPassword('') }
  }

  return <Modal title="Sincronizar NF-e" description="Consulte documentos destinados ao CNPJ usando o serviço oficial de distribuição." onClose={onClose}><form className="dialog-form" onSubmit={sync}><div className="dialog-note"><ShieldCheck size={16}/>Ambiente: <strong>{NFE_ENVIRONMENT === 1 ? 'Producao' : 'Homologacao (sem validade fiscal)'}</strong>. {NFE_ENVIRONMENT === 1 ? 'A consulta acessara os dados reais do CNPJ.' : 'Cursores e documentos deste ambiente ficam separados dos dados de producao.'}</div><label className="field-label">Empresa<select value={companyId} onChange={(event) => setCompanyId(event.target.value)} required><option value="">Selecione a empresa</option>{companies.map((item) => <option key={item.id} value={item.id}>{item.name} · {formatCnpj(item.cnpj)}</option>)}</select></label><label className="field-label">Certificado digital A1<input type="file" accept=".pfx,.p12,application/x-pkcs12" onChange={(event) => setFile(event.target.files?.[0] ?? null)} required/><small>O arquivo é enviado diretamente à API fiscal e não fica salvo no navegador.</small></label><label className="field-label">Senha do certificado<input type="password" autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} required/></label><div className="dialog-note"><ShieldCheck size={16}/>A senha e o arquivo do certificado não são armazenados. A consulta inicial pode retornar somente os documentos ainda disponíveis no Ambiente Nacional.</div>{working && <div className="sync-progress"><span className="spinner"/>{phase}</div>}<div className="dialog-actions"><button type="button" className="button button-secondary" onClick={onClose} disabled={working}>Cancelar</button><button className="button button-primary" disabled={working || !file || !companyId}>{working ? 'Sincronizando…' : 'Iniciar consulta'}<ArrowRight size={16}/></button></div></form></Modal>
}

function parseFiscalXml(xml: string) {
  const document = new DOMParser().parseFromString(xml, 'application/xml')
  if (document.querySelector('parsererror')) throw new Error('A SEFAZ retornou um XML que não pôde ser interpretado.')
  const text = (name: string, root: ParentNode = document) => {
    const item = root.querySelector(name) ?? Array.from(root.querySelectorAll('*')).find((node) => node.localName === name)
    return item?.textContent?.trim() || null
  }
  const element = (name: string) => Array.from(document.getElementsByTagName('*')).find((node) => node.localName === name) ?? null
  const emit = element('emit'); const dest = element('dest'); const ide = element('ide'); const total = element('ICMSTot')
  const id = element('infNFe')?.getAttribute('Id') ?? null
  const rawKey = text('chNFe') ?? id?.replace(/^NFe/, '') ?? null
  const amount = text('vNF', total ?? document)
  const issuedAt = text('dhEmi', ide ?? document) ?? text('dEmi', ide ?? document)
  const parsedDate = issuedAt ? new Date(issuedAt) : null
  return {
    accessKey: rawKey && /^\d{44}$/.test(rawKey) ? rawKey : null,
    issuerName: text('xNome', emit ?? document), issuerCnpj: text('CNPJ', emit ?? document),
    recipientName: text('xNome', dest ?? document), recipientCnpj: text('CNPJ', dest ?? document),
    number: text('nNF', ide ?? document), series: text('serie', ide ?? document),
    issuedAt: parsedDate && !Number.isNaN(parsedDate.getTime()) ? parsedDate.toISOString() : null,
    total: amount && /^\d+(\.\d+)?$/.test(amount) ? Number(amount) : null,
    situation: element('resNFe') ? 'Resumo disponível' : 'Documento recebido',
  }
}

async function sha256(value: string) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value))
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('')
}

function DocumentTable({ documents, onOpen }: { documents: FiscalDocument[]; onOpen: (document: FiscalDocument) => void }) {
  return <div className="table-scroll"><table><thead><tr><th>Documento</th><th>Emitente</th><th>Destinatário</th><th>Emissão</th><th>Valor</th><th>Situação</th><th><span className="sr-only">Abrir XML</span></th></tr></thead><tbody>{documents.map((item) => <tr key={item.id} onClick={() => onOpen(item)} tabIndex={0} onKeyDown={(event) => { if (event.key === 'Enter') onOpen(item) }}><td><div className="document-cell"><span className="doc-icon"><FileText size={16}/></span><span><strong>{item.document_number ? `NF-e ${item.document_number}` : 'NF-e recebida'}</strong><small>{shortKey(item.access_key)} · NSU {item.nsu}</small></span></div></td><td><div className="party-cell"><strong>{item.issuer_name ?? 'Emitente não identificado'}</strong><small>{formatCnpj(item.issuer_cnpj ?? '')}</small></div></td><td><div className="party-cell"><strong>{item.recipient_name ?? 'Destinatário não identificado'}</strong><small>{formatCnpj(item.recipient_cnpj ?? '')}</small></div></td><td className="date-cell">{formatDate(item.issued_at)}</td><td className="amount-cell">{formatMoney(item.total_amount)}</td><td><span className="status-badge"><span/> {item.situation}</span></td><td><button className="row-action" aria-label="Abrir XML" onClick={(event) => { event.stopPropagation(); onOpen(item) }}><ArrowRight size={16}/></button></td></tr>)}</tbody></table></div>
}

async function openDocumentXml(document: FiscalDocument) {
  if (!supabase) return
  const { data, error } = await supabase.storage.from('fiscal-xml').createSignedUrl(document.storage_path, 60)
  if (error) throw error
  window.open(data.signedUrl, '_blank', 'noopener,noreferrer')
}

function EmptyDocuments({ hasCompanies, onAddCompany, onSync }: { hasCompanies: boolean; onAddCompany: () => void; onSync: () => void }) {
  return <div className="empty-state"><div className="empty-art"><div className="empty-sheet sheet-back"/><div className="empty-sheet sheet-front"><span/><span/><span/></div><div className="empty-search"><Search size={18}/></div></div><h3>{hasCompanies ? 'Sua caixa fiscal está vazia' : 'Comece adicionando uma empresa'}</h3><p>{hasCompanies ? 'Faça uma consulta ao Ambiente Nacional para buscar documentos destinados a uma empresa.' : 'Cadastre um CNPJ para organizar os documentos fiscais recebidos.'}</p><button className="button button-primary" onClick={hasCompanies ? onSync : onAddCompany}>{hasCompanies ? <><ArrowDownToLine size={16}/>Sincronizar NF-e</> : <><Plus size={16}/>Adicionar empresa</>}</button></div>
}

function CompaniesPage({ companies, loading, onAdd, onSync }: { companies: Company[]; loading: boolean; onAdd: () => void; onSync: (id: string) => void }) {
  return <><section className="page-heading"><div><div className="eyebrow"><span className="eyebrow-line"/> ORGANIZAÇÃO</div><h1>Empresas</h1><p>Gerencie os CNPJs conectados à sua busca fiscal.</p></div><button className="button button-primary" onClick={onAdd}><Plus size={17}/>Adicionar empresa</button></section><section className="companies-card"><div className="results-heading"><div><h2>Empresas cadastradas</h2><p>{companies.length} CNPJ{companies.length === 1 ? '' : 's'} nesta organização</p></div></div>{loading ? <div className="loading-state"><div className="spinner"/><span>Carregando empresas…</span></div> : companies.length ? <div className="company-list">{companies.map((company) => <article className="company-row" key={company.id}><div className="company-symbol"><Building2 size={19}/></div><div className="company-details"><strong>{company.name}</strong><span>{formatCnpj(company.cnpj)}<i/> {company.state}</span></div><div className="company-connector"><span className="connector-dot"/>NF-e 55</div><button className="button button-secondary button-small" onClick={() => onSync(company.id)}><RefreshCw size={14}/>Sincronizar</button></article>)}</div> : <EmptyDocuments hasCompanies={false} onAddCompany={onAdd} onSync={onAdd}/>}</section><footer className="content-footer"><span>O escopo de cada consulta respeita o CNPJ cadastrado.</span><span><span className="footer-dot"/> Supabase local</span></footer></>
}

function Modal({ title, description, onClose, children }: { title: string; description: string; onClose: () => void; children: ReactNode }) {
  useEffect(() => { const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose() }; window.addEventListener('keydown', onKey); return () => window.removeEventListener('keydown', onKey) }, [onClose])
  return <div className="modal-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}><section className="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-title"><div className="modal-heading"><div><h2 id="modal-title">{title}</h2><p>{description}</p></div><button className="icon-button" onClick={onClose} aria-label="Fechar janela"><X size={18}/></button></div>{children}</section></div>
}

export default App
