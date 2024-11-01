<?php
/*
Plugin Name: WP e-Commerce Akatus Gateway
Plugin URI: http://www.omniwp.com.br
Description: Adiciona a opção de pagamento pela Akatus ao WP e-Commerce.
Version: 1.0
Author: omniWP
Author URI: http://www.omniwp.com.br

	Copyright: © 2012 omniWP
	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html

*/


if ( ! file_exists(  WP_PLUGIN_DIR . '/wp-e-commerce/wpsc-includes/merchant.class.php' ) ) {
	function show( $message, $error_msg = false) {
		if ($error_msg) {
			echo '<div id="message" class="error">';
		} else {
			echo '<div id="message" class="updated fade">';
		}
		echo '<p><strong>$message</strong></p></div>';
	}
	function show_admin_message() {
		show( 'O plugin WP e-Commerce Akatus Gateway necessita que o plugin WP e-Commerce esteja instalado.', true );
	}
	add_action( 'admin_notices', 'show_admin_message' );
	return;
}

$logo_url = WP_PLUGIN_URL . '/wp-e-commerce-akatus-gateway/images/akatus.png';

 /*
  * This is the gateway variable $nzshpcrt_gateways, 
  * it is used for displaying gateway information on the wp-admin pages
  * and also for internal operations.
  */

global $nzshpcrt_gateways;

$nzshpcrt_gateways[$num] = array(
	'api_version'     => 2.0,
	'name'            => 'Akatus',
	'display_name'    => 'Akatus + Fácil Pagar',	
	'image'           =>  $logo_url,

	'class_name'      => 'wpsc_merchant_akatus',
	
	'form'            => 'admin_form_akatus',
	'submit_function' => 'admin_submit_akatus',
		
	'internalname'    => 'wpsc_merchant_akatus'
);
	
require_once( WP_PLUGIN_DIR . '/wp-e-commerce/wpsc-includes/merchant.class.php' );
	
class wpsc_merchant_akatus extends wpsc_merchant {
	
		public function __construct() {
			wp_register_style( 'jquery-ui-css', plugins_url( '/js/theme/jquery.ui.all.css', __FILE__) );
			wp_enqueue_style( 'jquery-ui-css' );
			wp_enqueue_script( 'jquery-ui' );
			wp_enqueue_script( 'jquery-ui-accordion' );

			add_filter( 'wpsc_pre_transaction_results',     array( $this, 'akatus_transaction_results' ) ); 
			add_filter( 'wpsc_email_message',               array( $this, 'add_payment_link' ), 10, 6 ); 
			
			add_action( 'init',                             array( $this, 'resposta_nip' ) );
			add_action( 'wpsc_user_log_after_order_status', array( $this, 'show_payment_link' ), 10, 1 ); 
			add_action( 'wpsc_inside_shopping_cart',        'wp_e_commerce_akatus_form' , 10, 1 ); 
			
		}
	
		/**
		 * Resposta NIP, atualizar status
		 **/
			/*
			$processed = 1  payment declined     wpsc_payment_failed
			$processed = 2  payment on hold      wpsc_payment_incomplete
			$processed = 3  payment worked       wpsc_payment_successful
						
			<option value="1">Venda Incompleta</option>
			<option value="2">Pedido Recebido</option>
			<option value="3">Pagamento Aceito</option>
			<option value="4">Pedido Remetido</option>
			<option value="5">Pedido Fechado</option>
			<option value="6">Pagamento Recusado</option>
			*/
	
		function resposta_nip() {
			global $wpdb;
			// vem o token correto ?
			if ( get_option('akatus_token') == $this->get_request( 'token' ) ) {
				// atualizar o status do pedido
				$query = $wpdb->prepare( "
					SELECT sessionid, processed, notes 
					FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` 
					WHERE `transactid` = '%s' 
					LIMIT 1",  $this->get_request( 'transacao_id' ) );
				list( $sessionid, $processed, $current_notes ) = $wpdb->get_row( $query, ARRAY_N );
				$new_notes     = '';
				$new_processed = $processed;
				if ( ! empty( $current_notes ) ) {
					$new_notes = $current_notes . '<br />' . date( 'd/m/y H:i:s' ) . ' - '  ;
				}
				if ( 'Aprovado' == $this->get_request( 'status' ) ) {
					// estava esperando, pode completar
					if ( '2' == $processed ) {
						$new_processed = 3; // payment worked
						$new_notes    .= 'Pagamento Akatus completo';
					} else {
						// status desconhecido, somente registra que pagamento está completo
						$new_notes    .= 'Pagamento Akatus completo';
					}
				} elseif ( 'Cancelado' == $this->get_request( 'status' ) ) {
					// estava esperando, pode cancelar
					if ( '2' == $processed ) {
						$new_processed = 6; // payment declined 
						$new_notes    .= 'Pagamento Akatus cancelado';
					} else {
						// status desconhecido, somente registra que pagamento está cancelado
						$new_notes    .= 'Pagamento Akatus cancelado';
					}
				} elseif ( 'Em Análise' == $this->get_request( 'status' ) ) {
					$new_notes    .= 'Pagamento Akatus em análise de risco';
				} else {
					$new_notes    .=  $this->get_request( 'status' ) . ' Ping from ' . gethostbyaddr($_SERVER['REMOTE_ADDR']);
				}
				$data = array(
					'processed'  => $new_processed,
					'notes'      => $new_notes, 
					'date'       => time()
				);
				$where  = array( 'transactid' => $this->get_request( 'transacao_id' ) );
				$format = array( '%d', '%s', '%s' );
				$wpdb->update( WPSC_TABLE_PURCHASE_LOGS, $data, $where, $format );
				transaction_results( $sessionid, false );
			}
		}
		
		/**
		 * Adiciona link para pagamento ao conteudo do email enviado quando fecha o pedido
		 */
		public function add_payment_link( $message ) {
			global $wpdb;
			$query = $wpdb->prepare( "
				SELECT id, processed, notes 
				FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` 
				WHERE `sessionid` = '%s' 
				LIMIT 1",  $_SESSION['wpsc_sessionid'] );
			list( $id, $processed, $notes ) = $wpdb->get_row( $query, ARRAY_N );
			if ( $notes ) {
				$notes = '
' . $notes . '
';
			}
			return	$messsage . $notes;
		}

		/**
		 * Página de transaçoes do cliente, mostrar o link para pagamento que está nas notas do pedido
		 */
		public function show_payment_link( $purchase ) {
			if ( 2 == $purchase['processed'] ) {          // pagamento pendente 
				echo '<p>' . $purchase['notes'] . '</p>'; // o link para pagamento está nas notas do pedido
			}
		}

		/**
		 * Página de resultado da transação, mostrar o link para pagamento que está nas notas do pedido
		 */
		public function akatus_transaction_results() {
			global $wpdb;
			$query = $wpdb->prepare( "
				SELECT id, processed, notes 
				FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` 
				WHERE `sessionid` = '%s' 
				LIMIT 1",  $this->get_request( 'sessionid' ) );
			list( $id, $processed, $notes ) = $wpdb->get_row( $query, ARRAY_N );
			if ( $id ) {
				return	'
					<h3>Compra #' . $id . '</h3>
					<p>' . $notes . '</p>
					';
			}
		}
	
		/**
		 * Processar o formulário de envio de pedido
		 */
		public function submit() {
	
			global $wpdb;
			// Validar dados enviados
			$valid = false;
			if ( 'wpsc_merchant_akatus' == $this->get_request('custom_gateway') ) { 
				
				$valid    = true;
				if (  'BR' !=  $_REQUEST['collected_data'][7][0]  ) { // pais
						$valid = false;
						$this->set_error_message( 'País inválido. Somente Brasil é permitido.'  );
				} else { 
					if ( 2 != strlen(  $_REQUEST['collected_data'][6] ) ) { // estado
						$valid = false;
						$this->set_error_message( 'Estado inválido. Deve ser informada somente a sigla de 2 letras'  );
					} else { 
						$estados = array( 
							"AC"=>"Acre", 
							"AL"=>"Alagoas", 
							"AM"=>"Amazonas", 
							"AP"=>"Amapá",
							"BA"=>"Bahia",
							"CE"=>"Ceará",
							"DF"=>"Distrito Federal",
							"ES"=>"Espírito Santo",
							"GO"=>"Goiás",
							"MA"=>"Maranhão",
							"MT"=>"Mato Grosso",
							"MS"=>"Mato Grosso do Sul",
							"MG"=>"Minas Gerais",
							"PA"=>"Pará",
							"PB"=>"Paraíba",
							"PR"=>"Paraná",
							"PE"=>"Pernambuco",
							"PI"=>"Piauí",
							"RJ"=>"Rio de Janeiro",
							"RN"=>"Rio Grande do Norte",
							"RO"=>"Rondônia",
							"RS"=>"Rio Grande do Sul",
							"RR"=>"Roraima",
							"SC"=>"Santa Catarina",
							"SE"=>"Sergipe",
							"SP"=>"São Paulo",
							"TO"=>"Tocantins" );
						if ( ! array_key_exists( $_REQUEST['collected_data'][6], $estados ) ) {
							$valid = false;
							$this->set_error_message( 'Estado inválido. A sigla <em><strong>' . $_REQUEST['collected_data'][6] . '</strong></em> não é correta.' );
						}
					}
				}
				$cep = preg_replace( '/[^0-9]+/', '', $_REQUEST['collected_data'][8] );
				if ( 8 != strlen( $cep ) ) {
					$valid = false;
					$this->set_error_message( 'CEP inválido. Deve ser informado o CEP com 8 números. (Exemplo: 91900-640)' );
				}
				$telefone = preg_replace( '/[^0-9]+/', '', $_REQUEST['collected_data'][18] );
				$tamanho  = strlen( $telefone );
				if ( $tamanho < 10 || $tamanho > 11 ){
					$valid = false;
					$this->set_error_message( 'Telefone inválido. Deve ser informado o código de área com 2 dígitos seguido do número do telefone com 8 ou 9 dígitos. (Exemplo: (11)999.999.999.' );
				}
				$numero_cartao = '';
				$data_cartao   = '';
				$data_Yn       = '';
				$cvv_cartao    = '';
				$nome_cartao   = '';
				$cpf_portador  = '';				
				$estado        = $_REQUEST['collected_data'][6];
				$cidade        = substr( $_REQUEST['collected_data'][5], 0 , 50 );
				$logradouro    = substr( $_REQUEST['collected_data'][4], 0, 255 );
				$numero        = '1234';
				$complemento   = 'complemento';
				$bairro        = 'bairro';
				$cep           = substr( preg_replace('/[^0-9]+/', '', $_REQUEST['collected_data'][8] ) , 0 , 8);
				$meio_de_pagamento = $this->get_request('formaPagamentoAkatus');
				
				
				if ( empty( $meio_de_pagamento ) ) {
					$this->set_error_message( 'Selecione a forma de pagamento desejada' );
					$valid = false;
				} elseif ( strstr( $meio_de_pagamento, 'cartao' ) ) {
					$numero_cartao = preg_replace('/[^0-9]+/', '', $this->get_request('card_number') );
					$data_cartao   = $this->get_request('expiry_month') . '/' . $this->get_request('expiry_year');
					$data_Yn       = $this->get_request('expiry_year') . $this->get_request('expiry_month');
					$cvv_cartao    = $this->get_request('cvv');
					$nome_cartao   = $this->get_request('name_on_card');
					$cpf_portador  = preg_replace('/[^0-9]+/', '', $this->get_request('cpf') );
					if ( empty( $numero_cartao ) ) {
						$this->set_error_message( 'Informe o número do cartão' );
						$valid = false;
					} elseif ( empty( $cvv_cartao ) ) {
						$this->set_error_message( 'Informe o número CVV do cartão' );
						$valid = false;
					} else {
						function mod10_check( $number ) {
							$sum = 0;
							$strlen = strlen($number);
							if ( $strlen < 13) { return false; }
							for($i=0;$i<$strlen;$i++) {
								$digit = substr( $number, $strlen - $i - 1, 1);
								if ( $i % 2 == 1) {
									$sub_total = $digit * 2;
									if ( $sub_total > 9) {
										$sub_total = 1 + ($sub_total - 10);
									}
								} else {
									$sub_total = $digit;
								}
								$sum += $sub_total;
							}
							if ( $sum > 0 && $sum % 10 == 0) { 
								return true; 
							}
							return false;
						}
						function check_cc( $numero_cartao, $cvv, $cartao_bandeira ) {
							$valid = true;
							$prefix = substr( $numero_cartao, 0, 1 );                
							switch( $cartao_bandeira ) {
								case "cartao_master":               
									if ( $prefix != 5 ) {
										$valid = false;                             
									} else  if ( strlen($numero_cartao) != 16 ) {
										$valid = false;                           
									} else if ( strlen($cvv) != 3 ) {
										$valid = false;
									}
								break;
								case "cartao_visa":
									if ( $prefix != 4 ) {
										$valid = false;                            
									} else if ( strlen( $numero_cartao) < 13 || strlen($numero_cartao) > 16 ) {
										$valid = false;                               
									} else  if ( strlen($cvv) != 3 ) {                           
										$valid = false;  
									}
								break;
								case "cartao_diners":
									if ( $prefix != 3 ) {
										$valid = false;                            
									} else if ( strlen($numero_cartao) != 14 ) {
										$valid = false;
									} else if ( strlen($cvv) != 3 ) {                           
										$valid = false;
									}
					
								break;
								case "cartao_amex":
									if ( $prefix != 3 ) {
										$valid = false;                             
									} else if ( strlen($numero_cartao) != 15 ) {
										$valid = false;                            
									} else if ( strlen($cvv) != 4 ) {                            
										$valid = false;
									}
								break;
								case "cartao_elo":
									if ( $prefix != 6 ) {
										$valid = false;                            
									} else if ( strlen( $numero_cartao ) != 16 ) {
										$valid = false;                               
									} else  if ( strlen( $cvv ) != 3 ) {                           
										$valid = false;  
									}
									break;
								}	
					
							return $valid;
						}
						if ( ! mod10_check( $numero_cartao ) ) {
							$this->set_error_message( 'O número informado do cartão está incorreto' );
							$valid = false;
						} elseif  ( ! check_cc( $numero_cartao, $cvv_cartao, $meio_de_pagamento ) ) {
							$this->set_error_message( 'Cartão inválido. Revise os dados informados e tente novamente.' );
							$valid = false;
						}
					}
					if ( $data_Yn < date( 'Yn') ) {
						$this->set_error_message( 'A data de validade do cartão está vencida' );
						$valid = false;
					}
					if ( empty( $nome_cartao ) ) {
						$this->set_error_message( 'Informe o nome do portador ' );
						$valid = false;
					}
					if ( empty( $cpf_portador ) ) {
						$this->set_error_message( 'Informe o CPF do portador' );
						$valid = false;
					} else {
						function valida( $cpf_portador ) {
							$valid = true;
							//Etapa 1: Cria um array com apenas os digitos numéricos, isso permite receber o cpf em diferentes formatos como "000.000.000-00", "00000000000", "000 000 000 00" etc...
							$cpf_portador_so_numeros = array();
							$j = strlen( $cpf_portador );
							for ( $i=0; $i < $j; $i++ ) {
								if ( is_numeric( $cpf_portador[$i] ) ) { 
											$cpf_portador_so_numeros[] = $cpf_portador[$i];
								}
							}
							//Etapa 2: Conta os dígitos, um cpf válido possui 11 dígitos numéricos.
							if ( count( $cpf_portador_so_numeros ) != 11 ) {
									$valid = false;
							} else {
							//Etapa 3: Combinações como 00000000000 e 22222222222 embora não sejam cpfs reais resultariam em cpfs válidos após o calculo dos dígitos verificares e por isso precisam ser filtradas nesta parte.
								for ( $i=0; $i<10; $i++ ) {
									if ( $cpf_portador_so_numeros[0] == $i && $cpf_portador_so_numeros[1] == $i && $cpf_portador_so_numeros[2] == $i 
										&& $cpf_portador_so_numeros[3] == $i && $cpf_portador_so_numeros[4] == $i && $cpf_portador_so_numeros[5] == $i 
										&& $cpf_portador_so_numeros[6] == $i && $cpf_portador_so_numeros[7] == $i && $cpf_portador_so_numeros[8] == $i ) {
										$valid = false;
										break;
									}
								}
							}
							//Etapa 4: Calcula e compara o primeiro dígito verificador.
							if ( $valid ) {
								$j = 10;
								for ( $i=0; $i<9; $i++ ) {
									$multiplica[$i] = $cpf_portador_so_numeros[$i] * $j;
									$j--;
								}
								$soma = array_sum( $multiplica );	
								$resto = $soma % 11;			
								if ( $resto < 2 ) {
									$digito_verificador = 0;
								} else {
									$digito_verificador = 11 - $resto;
								}
								if ( $digito_verificador != $cpf_portador_so_numeros[9] ) {
									$valid = false;
								}
							}
							//Etapa 5: Calcula e compara o segundo dígito verificador.
							if ( $valid ) {
								$j = 11;
								for ( $i=0; $i<10; $i++ ) {
									$multiplica[$i]= $cpf_portador_so_numeros[$i] * $j;
									$j--;
									}
								$soma = array_sum($multiplica);
								$resto = $soma % 11;
								if ( $resto < 2 ) {
									$digito_verificador = 0;
								} else {
									$digito_verificador = 11 - $resto;
								}
								if ( $digito_verificador != $cpf_portador_so_numeros[10] ) {
									$valid = false;
								}
							}
							//Etapa 6: Retorna o Resultado em um valor booleano.
							return $valid;					
						}
						if ( ! valida( $cpf_portador ) ) {
							$this->set_error_message( 'O CPF informado não é válido' );
							$valid = false;
						}
					}
				}
			}
			if ( $valid ) {
				$this->process_payment( $meio_de_pagamento, $numero_cartao, $cvv_cartao, $data_cartao, $nome_cartao, $cpf_portador, $estado, $cidade, $logradouro, $numero, $bairro, $cep  );
			} else {
				$_SESSION['saved_post']  = $_POST;
				$this->return_to_checkout();
			}
		}
		
		/**
		 * Process the payment 
		 **/
		private function process_payment( $meio_de_pagamento, $numero_cartao, $cvv_cartao, $data_cartao, $nome_cartao, $cpf_portador, $estado, $cidade, $logradouro, $numero, $bairro, $cep  ) {
		
			require_once 'include/include.php';
			
			$xml_carrinho = $this-> XML_header() 
				 . $this->XML_pagamento( $meio_de_pagamento, $numero_cartao, $cvv_cartao, $data_cartao, $nome_cartao, $cpf_portador,  $estado, $cidade, $logradouro, $numero, $bairro, $cep   );
				 
			$resposta_api = http_request( CARRINHO, $xml_carrinho );
			$xml_resposta = simplexml_load_string( $resposta_api, null, LIBXML_NOERROR );
			
			if ( $xml_resposta == null ) {
				$this->set_error_message( 'Não se comunicou com o site da Akatus.' );
			} elseif ( 'erro' == $xml_resposta->status ) {
				$this->set_error_message( 'Erro na comunicação com o site da Akatus: ' . $xml_resposta->descricao );
//				echo '<textarea>xml_carrinho'; print_r($xml_carrinho); echo '</textarea>';
//				echo '<pre>xml_resposta'; print_r($xml_resposta); echo '</pre>';
			} else {
				if ( 'Cancelado' == $xml_resposta->status ) {
	
					$this->set_error_message( 'Transação não autorizada, contatar o emissor do cartão.' );
					$_SESSION['saved_post']  = $_POST;
					$this->return_to_checkout();
	
				} elseif ( 'Aguardando Pagamento' == $xml_resposta->status || 'Em Análise' == $xml_resposta->status ) {
	
					if ( strstr( $meio_de_pagamento, 'cartao' ) ) {
						$meio_de_pagamento .= '_' . $this->get_request( 'parcelas' );
					} elseif ( strstr( $meio_de_pagamento, 'tef' ) ) {
						$meio_de_pagamento .= '_1';
					}
	
					global $wpdb;
					$sessionid       = $_SESSION['wpsc_sessionid'];
					$transactionid   = $xml_resposta->transacao;
					$message_html    = 'Forma de pagamento selecionada: ' . $this->get_descricao() . ' ';
					if ( ! strstr( $meio_de_pagamento, 'cartao' ) ) {
						$message_html .=  '<a href="' . $xml_resposta->url_retorno . '" target="_blank">Clique aqui para pagar</a>';
					}
	
					$data = array(
						'processed'  => 2,
						'transactid' => $transactionid,
						'notes'      => $message_html, 
						'date'       => time()
					);
					$where  = array( 'sessionid' => $sessionid );
					$format = array( '%d', '%s', '%s', '%s' );
					$wpdb->update( WPSC_TABLE_PURCHASE_LOGS, $data, $where, $format );
					transaction_results( $sessionid, true );
					$this->go_to_transaction_results( $sessionid );
	
				} else {
				// não é um status conhecido, como Aguardando Pagamento, Em Análise ou Cancelado
					$this->set_error_message( 'Status não configurado: ' . $xml_resposta->status );
					$this->set_error_message( 'Resposta XML Akatus: ' . htmlentities( print_r( $xml_resposta, true ), ENT_QUOTES, 'UTF-8' ) );
					$_SESSION['saved_post']  = $_POST;
					$this->return_to_checkout();
				}
			}
		}
		
		/**
		 * Ler as opções de pagamento do site da Akatus
		 */
		 public function ler_opcoes_akatus() {
			require_once 'include/include.php';
			$msg = $this-> XML_header() 
				 . $this->XML_meios_de_pagamento();
			$resposta_api = http_request( ENDERECO, $msg );
			$xml_resposta = simplexml_load_string( $resposta_api, null, LIBXML_NOERROR );
	
			if ( $xml_resposta == null ) {
				echo '<p>Erro! Não se comunicou com o site da Akatus.</p>';
			} elseif ( 'erro' == $xml_resposta->status ) {
				echo '<p>Erro na comunicação com o site da Akatus: ' . $xml_resposta->descricao . '</p>';
			} else {
				$this->boleto_akatus = 'no';
				$this->opcoes_cartoes_akatus    = array();
				$this->parcelas_cartoes_akatus  = array();
				$this->opcoes_tefs_akatus    = array();
				$this->parcelas_tefs_akatus  = array();
				
				foreach ( $xml_resposta->meios_de_pagamento as $meio_de_pagamento ) {
					foreach ( $meio_de_pagamento as $opcao ) {
						if ( 'Boleto Bancário' == $opcao->descricao ) {
							$this->boleto_akatus = 'yes';
						} elseif ( 'Cartão de Crédito' == $opcao->descricao ) {
							foreach ( $opcao->bandeiras as $bandeira ) {
								foreach( $bandeira as $cartao ) {
									$this->opcoes_cartoes_akatus[ (string) $cartao->codigo ]   = (string) $cartao->descricao;
									$this->parcelas_cartoes_akatus[ (string) $cartao->codigo ] = (string) $cartao->parcelas;
								}
							}
						} elseif ( 'TEF' == $opcao->descricao ) {
							foreach ( $opcao->bandeiras as $bandeira ) {
								foreach ( $bandeira as $tef ) {
									$this->opcoes_tefs_akatus[ (string) $tef->codigo ]   = (string) $tef->descricao;
									$this->parcelas_tefs_akatus[ (string) $tef->codigo ] = (string) $tef->parcelas;
								}
							}
						}
					}
				}
				return true;
			}
			return false;
		}
		
		/**
		 * Mostar as opções do site da Akatus
		 */
		public function mostrar_opcoes_akatus( $return = false ) {
			if ( $return ) {
				ob_start();
			}
			$this->ler_opcoes_akatus();
	?>
	<script>
	jQuery(document).ready(function() {
	jQuery( '#wpsc-payment-gateway-settings-panel').css('width', '100%');
	});
	</script>
	<table class="form-table">
		<tbody>
			<tr valign="top">
				<th scope="row" class="titledesc" colspan="2"><h4>Opções configuradas no site da Akatus</h4></th>
				</tr>
			<tr valign="top">
				<th scope="row" class="titledesc">Boleto bancário</th>
				<td class="forminp">
					<fieldset>
					<legend class="screen-reader-text"><span>Boleto bancário</span></legend>
					<label for="wp_e_commerce_akauts_boleto">
					<input name="wp_e_commerce_akauts_boleto" id="wp_e_commerce_akauts_boleto" value="1" type="checkbox" checked="checked" disabled="disabled" />
					Aceitar pagamento com boleto bancário<br />
					<span class="description"><strong>Esta seleção deve ser feita no site da Akatus</strong></span></label>
					<br>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="wp_e_commerce_akauts_tefs">Transferências</label>
				</th>
				<td class="forminp">
					<fieldset>
					<legend class="screen-reader-text"><span>Transferências</span></legend>
					<select multiple="multiple" class="multiselect" name="wp_e_commerce_akauts_tefs[]" id="wp_e_commerce_akauts_tefs"  disabled="disabled">
						<?php
						foreach ( $this->opcoes_tefs_akatus as $option_key => $option_value ) :
							echo '<option value="'.$option_key.'" selected="selected">'.$option_value.'</option>';
						endforeach;
	?>
					</select>
					<br />
					<span class="description"><strong>Esta seleção deve ser feita no site da Akatus</strong></span>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="wp_e_commerce_akauts_cartoes">Bandeiras aceitas</label>
				</th>
				<td class="forminp">
					<fieldset>
					<legend class="screen-reader-text"><span>Bandeiras aceitas</span></legend>
					<select multiple="multiple" class="multiselect" name="wp_e_commerce_akauts_cartoes[]" id="wp_e_commerce_akauts_cartoes"  disabled="disabled">
						<?php
						foreach ( $this->opcoes_cartoes_akatus as $option_key => $option_value ) :
							echo '<option value="'.$option_key.'" selected="selected">'.$option_value.'</option>';
						endforeach;
	?>
					</select>
					<br />
					<span class="description"><strong>Esta seleção deve ser feita no site da Akatus</strong></span>
					</fieldset>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label for="wp_e_commerce_akauts_cartoes">Parcelamento</label>
				</th>
				<td class="forminp">
					<fieldset>
					<legend class="screen-reader-text"><span>Parcelamento pelas bandeiras</span></legend>
					<select multiple="multiple" class="multiselect" name="wp_e_commerce_akauts_parcelamento[]" id="wp_e_commerce_akauts_parcelamento"  disabled="disabled">
						<?php
						foreach ( $this->parcelas_cartoes_akatus as $option_key => $option_value ) :
							echo '<option value="'.$option_key.'" selected="selected">'.  $this->opcoes_cartoes_akatus[$option_key] . ' em até ' .  $option_value . ' parcelas </option>';
						endforeach;
	?>
					</select>
					<br />
					<span class="description"><strong>Esta seleção vem pronta do site da Akatus</strong></span>
					</fieldset>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
			if ( $return ) {
				$output = ob_get_contents();
				ob_end_clean();
				return $output;
			}
		}
		
		/**
		 * Descrição da forma de pagamento escolhida
		 **/
		private function get_descricao() {
			list( $forma_pagamento,	
				$opcao,
				$parcela ) = explode( '_', $this->get_request( 'formaPagamentoAkatus' ) );
	
			if ( 'boleto' == $forma_pagamento ) {
				$descricao  = 'Boleto bancário';
			} elseif ( 'cartao' == $forma_pagamento  ) {
				$this->ler_opcoes_akatus();
				$descricao = $this->opcoes_cartoes_akatus[ $forma_pagamento . '_' . $opcao ];
				if ( $parcela  == 1 ) {
					$descricao .= ' à vista';
				} else {
					$descricao .= ' em ' . $parcela . ' parcelas';
				}
			} elseif ( 'tef' == $forma_pagamento ) {
				$this->ler_opcoes_akatus();
				$descricao = $this->opcoes_tefs_akatus[ $forma_pagamento . '_' . $opcao  ];
	/*
				if ( $parcela  == 1 ) {
					$descricao .= ' à vista';
				} else {
					$descricao .= ' em ' . $parcela . ' parcelas';
				}
	*/
			} else {
				$descricao = $forma_pagamento .  ' não configurada';
			}
			return $descricao;
		}
				
			
		private function XML_header() {
			return '<?xml version="1.0" encoding="UTF-8" ?>'; 
		}
	
		/**
		 * Gera XML para obter as opções meios de pagamento habilitadas na Akatus
		 */
		private function XML_meios_de_pagamento() {
			$this->email = get_option("akatus_username");
			$this->chave = get_option("akatus_chave");
			$msg = '
<meios_de_pagamento>
<correntista>
   <api_key>' . $this->chave . '</api_key>
   <email>' . $this->email . '</email>
</correntista>
</meios_de_pagamento>';
			return $msg;
		}
	
		/**
		 * Gera XML para pagamento na Akatus
		 */
		private function XML_pagamento( $meio_de_pagamento, $numero_cartao, $cvv_cartao, $data_cartao, $nome_cartao, $cpf_portador, $estado, $cidade, $logradouro, $numero, $bairro, $cep ) {
		
			global $wpdb;
	
			$this->email = get_option("akatus_username");
			$this->chave = get_option("akatus_chave");
			
			$billing_name  = $_REQUEST['collected_data'][2] . ' ' . $_REQUEST['collected_data'][3];
			$billing_email = $_REQUEST['collected_data'][9];
			$telefone      = preg_replace( '/[^0-9]+/', '', $_REQUEST['collected_data'][18] );
			
			$msg = '
<carrinho>
	<recebedor>
	   <api_key>' . $this->chave . '</api_key>
	   <email>' . $this->email . '</email>
	</recebedor>
	<pagador>
		<nome>' . $billing_name .  ' </nome>
		<email>' . $billing_email .  '</email>
		<enderecos>
            <endereco> 
                <tipo>entrega</tipo>
                <logradouro>' . $logradouro . '</logradouro>
				<numero>' . $numero . '</numero>
				<bairro>' . $bairro . '</bairro>
				<cidade>' . $cidade . '</cidade>
				<estado>' . $estado . '</estado>
				<pais>BRA</pais>
				<cep>' . $cep . '</cep>
            </endereco>
		</enderecos>
		<telefones>
			<telefone>
				<tipo>residencial</tipo>
				<numero>' . $telefone .  '</numero>
			</telefone>
		</telefones>
	</pagador>';
			$produtos       = '';
			$peso_total     = 0;
			$frete_total    = 0;
			$desconto_total = 0;
			
			$query = $wpdb->prepare( "
				SELECT * 
				FROM `" . WPSC_TABLE_PURCHASE_LOGS . "` 
				WHERE `sessionid` = %s", $_SESSION['wpsc_sessionid'] );
			$purchase_log = $wpdb->get_row( $query, ARRAY_A );
			
			$query = "
				SELECT * 
				FROM `" . WPSC_TABLE_CART_CONTENTS . "` 
				WHERE `purchaseid` = {$purchase_log['id']}";
			$cart_data = $wpdb->get_results( $query, ARRAY_A ); 
				
			foreach ( $cart_data as $cart_item ) {
				$peso     = $this->get_peso( $cart_item['prodid'] );
				$desconto = $this->get_desconto( $cart_item['prodid'] );
	
				$peso_total     += $peso;
				$frete_total    += $cart_item['pnp'];
				$desconto_total += $desconto;
	
				$produtos .= '
		<produto>
			<codigo>' . $cart_item['purchaseid'] .  '</codigo>
			<descricao>' . urlencode( $cart_item['name'] ) . '</descricao>
			<quantidade>' . $cart_item['quantity'] . '</quantidade>
			<preco>' . $cart_item['price'] .  '</preco>
			<peso>' . $peso . '</peso>
			<frete>' . $cart_item['pnp'] . '</frete>
			<desconto>' . $desconto . '</desconto>
		</produto>';
			}
			$msg .= '
	<produtos>' . $produtos . '
	</produtos>';
		if ( 'boleto' == $meio_de_pagamento ) {
			$msg .= '
	<transacao>
		<desconto_total>' . $desconto_total . '</desconto_total>
		<peso_total>' . $peso_total . '</peso_total>
		<frete_total>' . $frete_total . '</frete_total>
		<moeda>BRL</moeda>
		<referencia>' . $_SESSION['wpsc_sessionid'] .  '</referencia>
		<meio_de_pagamento>boleto</meio_de_pagamento>
	</transacao>';
			} elseif ( strstr( $meio_de_pagamento, 'tef' ) ) {
				$msg .= '
	<transacao>
		<desconto_total>' . $desconto_total . '</desconto_total>
		<peso_total>' . $peso_total . '</peso_total>
		<frete_total>' . $frete_total . '</frete_total>
		<moeda>BRL</moeda>
		<referencia>' . $_SESSION['wpsc_sessionid'] .  '</referencia>
		<meio_de_pagamento>' . $meio_de_pagamento . '</meio_de_pagamento>
	</transacao>';
			} elseif ( strstr( $meio_de_pagamento, 'cartao' ) ) {
				$msg .= '
	<transacao>
		<numero>' . $numero_cartao .  '</numero>
		<parcelas>' . $this->get_request( 'parcelas' ) .  '</parcelas>
		<codigo_de_seguranca>' . $cvv_cartao .  '</codigo_de_seguranca>
		<expiracao>' . $data_cartao . '</expiracao>
		<desconto_total>' . $desconto_total . '</desconto_total>
		<peso_total>' . $peso_total . '</peso_total>
		<frete_total>' . $frete_total . '</frete_total>
		<moeda>BRL</moeda>
		<referencia>' . $_SESSION['wpsc_sessionid'] .  '</referencia>
		<meio_de_pagamento>' . $meio_de_pagamento . '</meio_de_pagamento>
		<portador>
				  <nome>' . $nome_cartao .  '</nome>
				  <cpf>' . $cpf_portador .  '</cpf>
				 <telefone>' . $telefone .  '</telefone>
		</portador>
	</transacao>';
	}
	$msg .='
</carrinho>
	';
			return $msg;
		}
	
		/**
		 * Get desconto 
		 **/
		private function get_desconto( $id ) {
			$preco              = get_post_meta( $id, '_wpsc_price' );
			$preco_com_desconto = get_post_meta( $id, '_wpsc_special_price' );
			return number_format( (float)$preco - (float)$preco_com_desconto, 2, '.', '' );
		}
		
		/**
		 * Get peso if set
		 **/
		private function get_peso( $id ) {
			$meta = get_post_meta( $id, '_wpsc_product_metadata' );
			if ( empty( $meta ) ) {
				return 0;
			} else {
				return round( wpsc_convert_weight( $meta[0]['weight'], 'pound', 'gram', true ), 0 );
			}
		}
		
		/**
		 * Get $_REQUEST data if set
		 **/
		private function get_request( $name ) {
			if ( isset( $_REQUEST[ $name ] ) ) {
				return $_REQUEST[ $name ];
			} else {
				return NULL;
			}
		}
	}

add_action( 'plugins_loaded', 'wp_e_commerce_akatus_init' ); 

function wp_e_commerce_akatus_init() {
	if ( in_array( 'wpsc_merchant_akatus', ( array ) get_option( 'custom_gateway_options' ) ) ) {
		global $wpsc_cart, $gateway_checkout_form_fields;
		$valor = $wpsc_cart->total_price; 
		$gateway_checkout_form_fields["wpsc_merchant_akatus"] = monta_formulario( $valor );
	}
}

function wp_e_commerce_akatus_form() {
	if ( in_array( 'wpsc_merchant_akatus', ( array ) get_option( 'custom_gateway_options' ) ) ) {
		global $gateway_checkout_form_fields;
		$valor = wpsc_cart_total( false ); // not for display
		$gateway_checkout_form_fields["wpsc_merchant_akatus"] = monta_formulario( $valor );
	}
}
	
function admin_submit_akatus() {
	foreach (array("akatus_username", "akatus_token", "akatus_chave") as $field):
		if (isset($_POST[$field])) {
			update_option($field, $_POST[$field]);
		}
	endforeach;

	// Booleans
	foreach(array("akatus_sandbox_mode") as $field):
		if (isset($_POST[$field])) {
			update_option($field, (boolean)$_POST[$field]);
		}
	endforeach;

	return true;
}

function admin_form_akatus() {		

	global $logo_url;
	
	$akatus_username     = get_option('akatus_username');
	$akatus_token        = get_option('akatus_token');
	$akatus_chave        = get_option('akatus_chave');
	
	$akatus_sandbox_mode = (boolean)get_option('akatus_sandbox_mode');
	$sandbox_checked = $akatus_sandbox_mode ? "checked=\"checked\"" : "";
	
	$akatus_show_logo = (boolean)get_option('akatus_show_logo');
	$logo_checked = $akatus_show_logo ? "checked=\"checked\"" : "";
	


	/*
		Create the form
	*/
	$output = <<<EOF
		<tr>
			<td colspan="2" align="center">
				<a href="http://www.akatus.com/" title="Akatus"><img src="{$logo_url}" alt="Akatus" /></a>
			</td>
		</tr>
		<tr>
			<td>
				<input name="akatus_sandbox_mode" type="hidden" value="0" />
				<label for="akatus_sandbox_mode">Modo de operação</label>
			</td>
			<td>
				<label for="akatus_sandbox_mode">
					<input type="checkbox" name="akatus_sandbox_mode" id="akatus_sandbox_mode" value="1" {$sandbox_checked} /> Teste ( sandbox )
				</label>
			</td>
		</tr>

		<tr>
			<th>
				<label for="akatus_username">Email </label>
			</th>
			<td>
				<input type="text" name="akatus_username" id="akatus_username" value="{$akatus_username}" /><br />Deve ser o mesmo utilizado para fazer login na Akatus.
			</td>
		</tr>
		<tr>
			<th>
				<label for="akatus_token">Token NIP</label>
			</th>
			<td>
				<input type="text" name="akatus_token" id="akatus_token" value="{$akatus_token}" /><br />Este token se encontra na sua conta Akatus em <a href="https://www.akatus.com/painel/cart/token" target="akatus">Integração -&gt; Chaves de Segurança -&gt; <strong>Token NIP</strong>
			</td>
		</tr>
		<tr>
			<th>
				<label for="akatus_chave">Chave da API</label>
			</th>
			<td>
				<input type="text" name="akatus_chave" id="akatus_chave" value="{$akatus_chave}" /><br />Este token se encontra na sua conta Akatus em <a href="https://www.akatus.com/painel/cart/token" target="akatus">Integração -&gt; Chaves de Segurança -&gt; <strong>API Key</strong>
			</td>
		</tr>
EOF;
	$tmp = new wpsc_merchant_akatus();
	$output .= $tmp->mostrar_opcoes_akatus( true ) . '
	</table>';
	return $output;
}

	function monta_formulario( $valor ) {
		$accordion_count = 0;
		$formulario = '
	<tr>
		<td>
			<script type="text/javascript">
			jQuery(document).ready(function() {
				jQuery("#accordion").accordion({
					autoHeight: false,
					resize: true,
					refresh: true
				});
			});
			</script>
			<h4>Escolha a forma de pagamento</h4>';
	
		$tmp = new wpsc_merchant_akatus();
		if ( $tmp->ler_opcoes_akatus() ) {
			if ( isset( $_SESSION['saved_post'] ) ) {
				$_POST = $_SESSION['saved_post'];
				unset( $_SESSION['saved_post'] );
			}
	
			$formulario .= '
			<div id="accordion">
	';
	
			if ( 'yes' == $tmp->boleto_akatus ) {
				$accordion_count++;
				if ( 'boleto' == $_POST['formaPagamentoAkatus'] ) { 
					$checked = ' checked="checked"';
				} else {
					$checked = '';
				} 				
				$formulario .= '
				<h3 style="padding-left:2.5em;">Boleto bancário</h3>
				<div>
					<input type="radio" name="formaPagamentoAkatus" value="boleto" id="meio_boleto"' . $checked . ' />
					<label for="meio_boleto"><img src="' .  WP_PLUGIN_URL . '/wp-e-commerce-akatus-gateway/images/boleto.png" style="vertical-align:middle" alt="Boleto bancário"> R$ ' . number_format( $valor, 2, ',', '.' ) . '</label>
				</div>';
			}
	
			if ( sizeof( $tmp->opcoes_tefs_akatus ) ) {
				$accordion_count++;
				$formulario .= '
				<h3 style="padding-left:2.5em;">Transferências</h3>
				<div>';
				foreach( $tmp->opcoes_tefs_akatus as $key => $value ) {
					if ( $key != $_POST['formaPagamentoAkatus'] ) { 
						$checked = '';
					} else {
						$checked = ' checked="checked"';
						$formulario .= '
					<script type="text/javascript">
					jQuery(document).ready(function() {
						jQuery("#accordion").accordion( "option", "active", ' . $accordion_count . ' );
					});
					</script>';
					} 				
				
				$formulario .= '
					<input type="radio" name="formaPagamentoAkatus" value="' . $key . '" id="meio_' . $key . '"' . $checked . ' />
					<label for="meio_' . $key . '"><img src="' .  WP_PLUGIN_URL . '/wp-e-commerce-akatus-gateway/images/' . $key . '.jpg" style="vertical-align:middle; padding: 1em" alt="' . $value . '">' . $value  . ' R$ ' .  number_format( $valor, 2, ',', '.' )  . '</label>
					<br />';
						}			
				$formulario .= '
				</div>';
			}
	
			if ( sizeof( $tmp->opcoes_cartoes_akatus ) ) {
				$nro_maximo_de_parcelas = 999; 
				$valor_minimo_por_parcela = 5;
				$formulario .= '
				<h3 style="padding-left:2.5em;">Cartões</h3>
				<div>';
				foreach( $tmp->opcoes_cartoes_akatus as $key => $value ) {
					if ( $key != $_POST['formaPagamentoAkatus'] ) { 
						$checked = '';
					} else {
						$checked = ' checked="checked"';
						$formulario .= '
					<script type="text/javascript">
					jQuery(document).ready(function() {
						jQuery("#accordion").accordion( "option", "active", ' . $accordion_count . ' );
					});
					</script>';
					} 				
					$k = 1;	
					$id = 'meio_' . $key .'_' . $k;
					$formulario .= '
					<input type="radio" name="formaPagamentoAkatus" value="' . $key . '" id="' .  $id . '"' . $checked . ' />
					<label for="' . $id . '"><img src="' .  WP_PLUGIN_URL . '/wp-e-commerce-akatus-gateway/images/' . $key . '.png" style="vertical-align:middle" alt="' . $value . '">' . $value . '</label>
					<br />';
					// calcular nro de parcelas, respeitando a parcela mínima 
					$i = $tmp->parcelas_cartoes_akatus[ $key ];
					if ( $nro_maximo_de_parcelas > $i ) {
						if ( ( $valor / $i ) < $valor_minimo_por_parcela ) {
							$i--;
							while ( $i > 0 && ( ( $valor / $i ) < $valor_minimo_por_parcela ) )  {
								$i--;
							}
						}
						if ( $i < $nro_maximo_de_parcelas ) {
							$nro_maximo_de_parcelas = $i;
						}
					}
				}
				if ( '1' == $_POST['parcelas'] ) { 
					$selected = ' selected="selected"';
				} else {
					$selected = '';
				}
				$options = '<option value="1"' . $selected. '>à vista R$ ' . number_format( $valor, 2, ',', '.' ) . '</option>';
				for ( $j = 2; $j <= $nro_maximo_de_parcelas; $j++ ) {
					if ( $j == $_POST['parcelas'] ) { 
						$selected = ' selected="selected"';
					} else {
						$selected = '';
					}
					$options .= '
								<option value="' . $j . '"' . $selected. '>Crédito ' .  $j . 'x R$ ' . number_format( $valor/$j, 2, ',', '.' ) . '</option>';
				}
				$month_options = '';
				if ( isset( $_POST['expiry_month'] ) ) {
					$selected_month = $_POST['expiry_month'];
				} else {	
					$selected_month = date( 'm' );
				}
				for ( $j = 1; $j <= 12; $j++ ) {
					$k = sprintf( '%02d', $j );
					if ( $k == $selected_month ) {
						$month_options .= '
								<option value="' . $k  . '" selected="selected">' . $k . '</option>';
					} else {
						$month_options .= '
								<option value="' . $k  . '">' . $k . '</option>';
					}
				}
				$year = date( 'Y' );
				$year_options = '';
				if ( isset( $_POST['expiry_year'] ) ) {
					$selected_year = $_POST['expiry_year'];
				} else {	
					$selected_year = $year;
				}
				for ( $j = 1;  $j <= 8; $j++ ) {
					if ( $year == $selected_year ) {
						$year_options .= '
								<option value="' . $year . '" selected="selected">' . $year  . '</option>';
					} else {
						$year_options .= '
								<option value="' . $year . '">' . $year  . '</option>';
					}
					$year++;
				}
				$formulario .= '
					<ul>
						<li>
							<label for="parcelas">Forma de pagamento</label>
							<select name="parcelas" id="parcelas">
								' . $options . '
							</select>
						</li>
						<li>
							<label for="card_number">Número do cartão</label>
							<input type="text" name="card_number" id="card_number" maxlength="20" size="21" value="' . $_POST['card_number'] .'" />
						</li>
						<li>
							<label for="expiry_month">Data de validade</label>
							<select name="expiry_month">'
								 . $month_options . '
							</select>
							<select name="expiry_year">'
								 . $year_options . '
							</select>
						</li>
						<li>
							<label for="cvv">CVV</label>
							<input type="text" name="cvv" id="cvv" maxlength="3" size="4" value="' . $_POST['cvv'] .'" />
						</li>
						<li>
							<label for="name_on_card">Nome do portador</label>
							<input type="text" name="name_on_card" id="name_on_card" value="' . $_POST['name_on_card'] .'" />
						</li>
						<li>
							<label for="cpf">CPF do portador</label>
							<input type="text" name="cpf" id="cpf" maxlength="14" size="15" value="' . $_POST['cpf'] .'" />
						</li>
					</ul>
				</div>';
			}
	
			$formulario .= '
			</div>
		</td>
	</tr>
	';
		return $formulario;
		}
	}


?>