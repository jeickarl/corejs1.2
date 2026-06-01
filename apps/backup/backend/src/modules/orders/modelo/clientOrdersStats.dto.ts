import { ApiProperty } from '@nestjs/swagger';

export class ClientOrdersStatsDto {
  @ApiProperty({ example: 120 })
  total!: number;

  @ApiProperty({ example: 20 })
  pending!: number;

  @ApiProperty({ example: 60 })
  inProcess!: number;

  @ApiProperty({ example: 40 })
  completed!: number;
}

