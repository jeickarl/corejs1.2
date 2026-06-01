import { ApiProperty } from '@nestjs/swagger';

export class WhatsappTemplatesDto {
  @ApiProperty({ example: 'Hola {cliente}, recibimos tu equipo...' })
  reception!: string;

  @ApiProperty({ example: 'Tu equipo está listo...' })
  ready!: string;

  @ApiProperty({ example: 'Puedes pasar a retirar...' })
  delivery!: string;

  @ApiProperty({ example: 'Gracias por tu compra...' })
  sale!: string;
}

