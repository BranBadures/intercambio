<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Pedido;
use App\Models\Disciplina;
use Uspdev\Replicado\Pessoa;
use App\Http\Controllers\Redirect;
use Illuminate\Support\Facades\URL;

use Mail;
use App\Mail\email_analise_aluno;
use App\Mail\email_em_elaboracao_aluno;
use App\Mail\email_analise_ccint;
use App\Mail\email_indeferido;
use App\Mail\email_deferido;
use App\Mail\email_docente;
use App\Service\Utils;

class WorkflowController extends Controller
{
    public function updatePedidoStatus(Request $request, Pedido $pedido){
        $this->authorize('owner',$pedido);

        $request->validate([
            'status' => Rule::in(['Em elaboração', 'Análise', 'Comissão de Graduação','Serviço de Graduação','Deferido']),
        ]);

        if($request->status == 'Em elaboração'){
            $request->validate([
                'comentario' => 'required',
            ]);
        }

        if($request->status == 'Comissão de Graduação'){
            $this->authorize('admin');
            foreach($pedido->disciplinas as $disciplina) {
                if($disciplina->tipo != 'Obrigatória' && empty($disciplina->conversao)){
                    request()->session()->flash('alert-danger',"A disciplina {$disciplina->nome} não teve os créditos convertidos");
                    return back();
                }
            }
        }

        if($request->status == 'Serviço de Graduação'){
            $this->authorize('admin');
            foreach($pedido->disciplinas as $disciplina) {
                if($disciplina->status == 'Indeferido'){
                    request()->session()->flash('alert-danger',"A disciplina {$disciplina->nome} foi indeferida e não pode ser enviada para o Serviço de Graduação");
                    return back();
                }
            }
        }

        if($request->status == 'Deferido'){
            $this->authorize('admin');
            Mail::queue(new email_deferido($pedido));
        }

        foreach($pedido->disciplinas as $disciplina) {
            $disciplina->setStatus($request->status, $request->comentario);
        }

        Utils::updatePedidoStatus($pedido, $request->comentario);

        if($request->status =='Em elaboração') {
            Mail::queue(new email_em_elaboracao_aluno($pedido));
        }else if($request->status=='Análise') {
            Mail::queue(new email_analise_aluno($pedido));
            Mail::queue(new email_analise_ccint($pedido));
        }
        return redirect("/pedidos/$pedido->id");
    }

    public function salvardocente(Request $request, Disciplina $disciplina)
    {
        $this->authorize('admin');
        // TODO: Validar se é um docente
        $request->validate([
            "codpes_docente" => "required|integer"
        ]);

        $disciplina->codpes_docente = $request->codpes_docente;
        $disciplina->save();
        Mail::queue(new email_docente($disciplina, $request->codpes_docente));

        return redirect("/pedidos/$disciplina->pedido_id");
    }

    public function docente(Request $request)
    {
        $this->authorize('docente');  
        $disciplinas = Disciplina::where('codpes_docente',auth()->user()->codpes)
                                  ->whereNull('deferimento_docente')->get();

        return view('disciplinas.docente',[
            'disciplinas' => $disciplinas,
        ]);
    }

    public function show_parecer(Disciplina $disciplina, Request $request)
    {
        // verificar se o docente em questão é o owner do parecer
        $this->authorize('docente');
        return view('disciplinas.parecer',[
            'docentes'   =>  $docentes = Pessoa::listarDocentes(),
            'disciplina' => $disciplina,
        ]);
    }

    public function store_parecer(Disciplina $disciplina, Request $request)
    {
        // verificar se o docente em questão é o owner do parecer
        $this->authorize('docente');  

        $request->validate([
            "comentario" => "required"
        ]);

        if($request->parecer == 'indicar'){
            $request->validate([
                "codpes" => "required"
            ]);
            $disciplina->setStatus('Comissão de Graduação', $request->comentario);
            $disciplina->codpes_docente = $request->codpes;
            $disciplina->save();
            Mail::queue(new email_docente($disciplina, $request->codpes));
            request()->session()->flash('alert-info',"Obrigado pela indicação");
            return redirect("/docente");
        }

        if($request->parecer == 'indeferir'){
            $disciplina->setStatus('Indeferido', $request->comentario);
            $disciplina->save();
            Mail::queue(new email_indeferido($disciplina));
            request()->session()->flash('alert-info',"Indeferimento realizado com sucesso");
            return redirect("/docente");
        }

        $disciplina->deferimento_docente = 'Sim';
        $disciplina->setStatus('Comissão de Graduação', $request->comentario);
        $disciplina->save();

        request()->session()->flash('alert-info',"Parecer realizado com sucesso");
        return redirect("/docente");
    }
}
